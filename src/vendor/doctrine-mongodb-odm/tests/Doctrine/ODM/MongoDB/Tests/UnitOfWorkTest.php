<?php

namespace Doctrine\ODM\MongoDB\Tests;

use Doctrine\ODM\MongoDB\Mapping\ClassMetadata;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\UnitOfWork;
use Doctrine\ODM\MongoDB\Persisters\PersistenceBuilder;
use Doctrine\ODM\MongoDB\Tests\Mocks\DocumentManagerMock;
use Doctrine\ODM\MongoDB\Tests\Mocks\ConnectionMock;
use Doctrine\ODM\MongoDB\Tests\Mocks\UnitOfWorkMock;
use Doctrine\ODM\MongoDB\Tests\Mocks\DocumentPersisterMock;
use Documents\ForumUser;
use Documents\ForumAvatar;

class UnitOfWorkTest extends \PHPUnit_Framework_TestCase
{
    private $dm;
    private $uow;

    protected function setUp()
    {
        parent::setUp();
        $this->dm = DocumentManagerMock::create(new ConnectionMock());
        $this->uow = $this->dm->getUnitOfWork();
    }

    protected function tearDown() {
        unset($this->dm, $this->uow);
    }

    public function testRegisterRemovedOnNewEntityIsIgnored()
    {
        $user = new ForumUser();
        $user->username = 'romanb';
        $this->assertFalse($this->uow->isScheduledForDelete($user));
        $this->uow->scheduleForDelete($user);
        $this->assertFalse($this->uow->isScheduledForDelete($user));
    }


    /* Operational tests */

    public function testSavingSingleEntityWithIdentityColumnForcesInsert()
    {
        // Setup fake persister and id generator for identity generation
        $pb = $this->getMockPersistenceBuilder();
        $class = $this->dm->getClassMetadata('Documents\ForumUser');
        $userPersister = $this->getMockDocumentPersister($pb, $class);
        $this->uow->setDocumentPersister('Documents\ForumUser', $userPersister);

        // Test
        $user = new ForumUser();
        $user->username = 'romanb';
        $this->uow->persist($user);

        // Check
        $this->assertEquals(0, count($userPersister->getInserts()));
        $this->assertEquals(0, count($userPersister->getUpdates()));
        $this->assertEquals(0, count($userPersister->getDeletes()));
        $this->assertFalse($this->uow->isInIdentityMap($user));
        // should no longer be scheduled for insert
        $this->assertTrue($this->uow->isScheduledForInsert($user));

        // Now lets check whether a subsequent commit() does anything
        $userPersister->reset();

        // Test
        $this->uow->commit();

        // Check.
        $this->assertEquals(1, count($userPersister->getInserts()));
        $this->assertEquals(0, count($userPersister->getUpdates()));
        $this->assertEquals(0, count($userPersister->getDeletes()));

        // should have an id
        $this->assertTrue(is_numeric($user->id));
    }

    /**
     * Tests a scenario where a save() operation is cascaded from a ForumUser
     * to its associated ForumAvatar, both entities using IDENTITY id generation.
     */
    public function testCascadedIdentityColumnInsert()
    {
        // Setup fake persister and id generator for identity generation
        //ForumUser
        $pb = $this->getMockPersistenceBuilder();
        $class = $this->dm->getClassMetadata('Documents\ForumUser');
        $userPersister = $this->getMockDocumentPersister($pb, $class);
        $this->uow->setDocumentPersister('Documents\ForumUser', $userPersister);

        // ForumAvatar
        $pb = $this->getMockPersistenceBuilder();
        $class = $this->dm->getClassMetadata('Documents\ForumAvatar');
        $avatarPersister = $this->getMockDocumentPersister($pb, $class);
        $this->uow->setDocumentPersister('Documents\ForumAvatar', $avatarPersister);

        // Test
        $user = new ForumUser();
        $user->username = 'romanb';
        $avatar = new ForumAvatar();
        $user->avatar = $avatar;
        $this->uow->persist($user); // save cascaded to avatar

        $this->uow->commit();

        $this->assertTrue(is_numeric($user->id));
        $this->assertTrue(is_numeric($avatar->id));

        $this->assertEquals(1, count($userPersister->getInserts()));
        $this->assertEquals(0, count($userPersister->getUpdates()));
        $this->assertEquals(0, count($userPersister->getDeletes()));

        $this->assertEquals(1, count($avatarPersister->getInserts()));
        $this->assertEquals(0, count($avatarPersister->getUpdates()));
        $this->assertEquals(0, count($avatarPersister->getDeletes()));
    }

    public function testChangeTrackingNotify()
    {
        $pb = $this->getMockPersistenceBuilder();
        $class = $this->dm->getClassMetadata("Doctrine\ODM\MongoDB\Tests\NotifyChangedDocument");
        $persister = $this->getMockDocumentPersister($pb, $class);
        $this->uow->setDocumentPersister('Doctrine\ODM\MongoDB\Tests\NotifyChangedDocument', $persister);

        $pb = $this->getMockPersistenceBuilder();
        $class = $this->dm->getClassMetadata("Doctrine\ODM\MongoDB\Tests\NotifyChangedRelatedItem");
        $itemPersister = $this->getMockDocumentPersister($pb, $class);
        $this->uow->setDocumentPersister('Doctrine\ODM\MongoDB\Tests\NotifyChangedRelatedItem', $itemPersister);

        $entity = new NotifyChangedDocument;
        $entity->setData('thedata');
        $this->uow->persist($entity);

        $this->uow->commit();
        $this->assertEquals(1, count($persister->getInserts()));
        $persister->reset();

        $this->assertTrue($this->uow->isInIdentityMap($entity));

        $entity->setData('newdata');
        $entity->setTransient('newtransientvalue');

        $this->assertTrue($this->uow->isScheduledForDirtyCheck($entity));

        $this->assertEquals(array('data' => array('thedata', 'newdata')), $this->uow->getDocumentChangeSet($entity));

        $item = new NotifyChangedRelatedItem();
        $entity->getItems()->add($item);
        $item->setOwner($entity);
        $this->uow->persist($item);

        $this->uow->commit();
        $this->assertEquals(1, count($itemPersister->getInserts()));
        $persister->reset();
        $itemPersister->reset();

        $entity->getItems()->removeElement($item);
        $item->setOwner(null);
        $this->assertTrue($entity->getItems()->isDirty());
        $this->uow->commit();
        $updates = $itemPersister->getUpdates();
        $this->assertEquals(1, count($updates));
        $this->assertTrue($updates[0] === $item);
    }

    public function testGetDocumentStateWithAssignedIdentity()
    {
        $pb = $this->getMockPersistenceBuilder();
        $class = $this->dm->getClassMetadata("Documents\CmsPhonenumber");
        $persister = $this->getMockDocumentPersister($pb, $class);
        $this->uow->setDocumentPersister('Documents\CmsPhonenumber', $persister);

        $ph = new \Documents\CmsPhonenumber();
        $ph->phonenumber = '12345';

        $this->assertEquals(UnitOfWork::STATE_NEW, $this->uow->getDocumentState($ph));
        $this->assertTrue($persister->isExistsCalled());

        $persister->reset();

        // if the document is already managed the exists() check should be skipped
        $this->uow->registerManaged($ph, '12345', array());
        $this->assertEquals(UnitOfWork::STATE_MANAGED, $this->uow->getDocumentState($ph));
        $this->assertFalse($persister->isExistsCalled());
        $ph2 = new \Documents\CmsPhonenumber();
        $ph2->phonenumber = '12345';
        $this->assertEquals(UnitOfWork::STATE_DETACHED, $this->uow->getDocumentState($ph2));
        $this->assertFalse($persister->isExistsCalled());
    }

    /**
     * @expectedException Doctrine\ODM\MongoDB\MongoDBException
     */
    public function testThrowsOnPersistOfMappedSuperclass()
    {
        $documentManager = $this->getDocumentManager();
        $documentManager->setClassMetadata('Documents\Address', $this->getClassMetadata('Documents\Address', 'MappedSuperclass'));
        $unitOfWork = $this->getUnitOfWork($documentManager);
        $unitOfWork->persist(new \Documents\Address());
    }

    public function testParentAssociations()
    {
        $a = new ParentAssociationTest('a');
        $b = new ParentAssociationTest('b');
        $c = new ParentAssociationTest('c');
        $d = new ParentAssociationTest('c');

        $documentManager = $this->getDocumentManager();
        $unitOfWork = $this->getUnitOfWork($documentManager);
        $unitOfWork->setParentAssociation($b, array('name' => 'b'), $a, 'b');
        $unitOfWork->setParentAssociation($c, array('name' => 'c'), $b, 'b.c');
        $unitOfWork->setParentAssociation($d, array('name' => 'd'), $c, 'b.c.d');

        $this->assertEquals(array(array('name' => 'd'), $c, 'b.c.d'), $unitOfWork->getParentAssociation($d));
    }

    protected function getDocumentManager()
    {
        return new \Stubs\DocumentManager();
    }

    protected function getUnitOfWork(DocumentManager $dm)
    {
        return new UnitOfWork($dm, $this->getMockEventManager(), $this->getMockHydrator(), '$');
    }

    /**
     * Gets mock Hydrator instance
     *
     * @return Doctrine\ODM\MongoDB\Hydrator
     */
    private function getMockHydrator()
    {
        return $this->getMockBuilder('Doctrine\ODM\MongoDB\Hydrator')
            ->disableOriginalClone()
            ->disableOriginalConstructor()
            ->getMock();
    }

    /**
     * Gets mock EventManager instance
     *
     * @return Doctrine\Common\EventManager
     */
    private function getMockEventManager()
    {
        return $this->getMockBuilder('Doctrine\Common\EventManager')
            ->disableOriginalClone()
            ->disableOriginalConstructor()
            ->getMock();
    }

    private function getMockPersistenceBuilder()
    {
        return $this->getMock('Doctrine\ODM\MongoDB\Persisters\PersistenceBuilder', array(), array(), '', false, false);
    }

    private function getMockDocumentPersister(PersistenceBuilder $pb, ClassMetadata $class)
    {
        return new DocumentPersisterMock($pb, $this->dm, $this->dm->getEventManager(), $this->uow, $this->dm->getHydrator(), $class, '$');
    }

    protected function getClassMetadata($class, $flag)
    {
        $classMetadata = new ClassMetadata($class);
        $classMetadata->{'is' . ucfirst($flag)} = true;
        return $classMetadata;
    }
}

class ParentAssociationTest
{
    public $name;
    public function __construct($name)
    {
        $this->name = $name;
    }
}

/**
 * @Document
 */
class NotifyChangedDocument implements \Doctrine\Common\NotifyPropertyChanged
{
    private $_listeners = array();
    /**
     * @Id
     */
    private $id;
    /**
     * @String
     */
    private $data;

    private $transient; // not persisted

    /** @ReferenceMany(targetDocument="NotifyChangedRelatedItem") */
    private $items;

    public function  __construct() {
        $this->items = new \Doctrine\Common\Collections\ArrayCollection;
    }

    public function getId() {
        return $this->id;
    }

    public function getItems() {
        return $this->items;
    }

    public function setTransient($value) {
        if ($value != $this->transient) {
            $this->_onPropertyChanged('transient', $this->transient, $value);
            $this->transient = $value;
        }
    }

    public function getData() {
        return $this->data;
    }

    public function setData($data) {
        if ($data != $this->data) {
            $this->_onPropertyChanged('data', $this->data, $data);
            $this->data = $data;
        }
    }

    public function addPropertyChangedListener(\Doctrine\Common\PropertyChangedListener $listener)
    {
        $this->_listeners[] = $listener;
    }

    protected function _onPropertyChanged($propName, $oldValue, $newValue) {
        if ($this->_listeners) {
            foreach ($this->_listeners as $listener) {
                $listener->propertyChanged($this, $propName, $oldValue, $newValue);
            }
        }
    }
}

/** @Document */
class NotifyChangedRelatedItem
{
    /**
     * @Id
     */
    private $id;

    /** @ReferenceOne(targetDocument="NotifyChangedDocument") */
    private $owner;

    public function getId() {
        return $this->id;
    }

    public function getOwner() {
        return $this->owner;
    }

    public function setOwner($owner) {
        $this->owner = $owner;
    }
}