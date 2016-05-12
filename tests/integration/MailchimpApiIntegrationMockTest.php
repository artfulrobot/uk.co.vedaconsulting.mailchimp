<?php
/**
 * @file
 * These tests run with a mocked Mailchimp API.
 *
 * They test that expected calls are made (or not made) based on changes in
 * CiviCRM.
 *
 */
/**
 * This tests CiviCRM using mocked Mailchimp API responses.
 *
 * It does not depend on a live Mailchimp account. However it is not a unit test
 * because it does depend on and make changes to the CiviCRM database.
 *
 * It is useful to mock the Maichimp API because
 * - It removes a dependency, so test results are more predictable.
 * - It is much faster to run
 * - It can be run without a Mailchimp account/api_key, and makes no changes to
 *   a mailchimp account, so could be seen as safer.
 */
require 'integration-test-bootstrap.php';

use \Prophecy\Argument;

class MailchimpApiIntegrationMockTest extends MailchimpApiIntegrationBase {

  public static function setUpBeforeClass() {
    static::createMailchimpMockFixtures();
    static::createCiviCrmFixtures();
  }
  /**
   * Set dummy fixtures.
   */
  public static function createMailchimpMockFixtures() {
    static::$test_list_id = 'dummylistid';
    static::$test_interest_category_id = 'categoryid';
    static::$test_interest_id_1 = 'interestId1';
    static::$test_interest_id_2 = 'interestId2';
  }
  /**
   * Remove the test list, if one was successfully set up.
   */
  public static function tearDownAfterClass() {
    static::tearDownCiviCrmFixtures();
  }

  /**
   * Checks the right calls are made by the getMCInterestGroupings.
   *
   * This is a dependency of some other tests because it also caches the result,
   * which means that we don't have to duplicate prophecies for this behaviour
   * in other tests.
   */
  public function testGetMCInterestGroupings() {

    // Get Mock API.
    $api_prophecy = $this->prophesize('CRM_Mailchimp_Api3');
    CRM_Mailchimp_Utils::setMailchimpApi($api_prophecy->reveal());

    // Creating a sync object will cause a load of interests for that list from
    // Mailchimp, so we prepare the mock for that.
    $api_prophecy->get("/lists/dummylistid/interest-categories", Argument::any())
      ->shouldBeCalled()
      ->willReturn(json_decode('{"http_code":200,"data":{"categories":[{"id":"categoryid","title":"'. self::MC_INTEREST_CATEGORY_TITLE . '"}]}}'));

    $api_prophecy->get("/lists/dummylistid/interest-categories/categoryid/interests", Argument::any())
      ->shouldBeCalled()
      ->willReturn(json_decode('{"http_code":200,"data":{"interests":[{"id":"interestId1","name":"' . self::MC_INTEREST_NAME_1 . '"},{"id":"interestId2","name":"' . self::MC_INTEREST_NAME_2 . '"}]}}'));

    $interests = CRM_Mailchimp_Utils::getMCInterestGroupings('dummylistid');
    $this->assertEquals([ 'categoryid' => [
      'id' => 'categoryid',
      'name' => self::MC_INTEREST_CATEGORY_TITLE,
      'interests' => [
        'interestId1' => [ 'id' => 'interestId1', 'name' => static::MC_INTEREST_NAME_1 ],
        'interestId2' => [ 'id' => 'interestId2', 'name' => static::MC_INTEREST_NAME_2 ],
      ],
    ]], $interests);

  }
  /**
   * Tests the mapping of CiviCRM group memberships to an array of Mailchimp
   * interest Ids => Bool.
   *
   * @depends testGetMCInterestGroupings
   */
  public function testGetComparableInterestsFromCiviCrmGroups() {

    $sync = new CRM_Mailchimp_Sync(static::$test_list_id);
    $g = static::C_TEST_MEMBERSHIP_GROUP_NAME;
    $i = static::C_TEST_INTEREST_GROUP_NAME_1;
    $j = static::C_TEST_INTEREST_GROUP_NAME_2;
    $cases = [
      // In both membership and interest1
      "$g,$i" => ['interestId1'=>TRUE,'interestId2'=>FALSE],
      // Just in membership group.
      "$g" => ['interestId1'=>FALSE,'interestId2'=>FALSE],
      // In interest1 only.
      "$i" => ['interestId1'=>TRUE,'interestId2'=>FALSE],
      // In lots!
      "$j,other list name,$g,$i,and another" => ['interestId1'=>TRUE,'interestId2'=>TRUE],
      // In both and other non MC groups.
      "other list name,$g,$i,and another" => ['interestId1'=>TRUE,'interestId2'=>FALSE],
      // In none, just other non MC groups.
      "other list name,and another" => ['interestId1'=> FALSE,'interestId2'=>FALSE],
      // In no groups.
      "" => ['interestId1'=> FALSE,'interestId2'=>FALSE],
      ];
    foreach ($cases as $input=>$expected) {
      $ints = $sync->getComparableInterestsFromCiviCrmGroups($input);
      $this->assertEquals($expected, $ints, "mapping failed for test '$input'");
    }

  }
  /**
   * Tests the mapping of CiviCRM group memberships to an array of Mailchimp
   * interest Ids => Bool.
   *
   * @depends testGetMCInterestGroupings
   */
  public function testGetComparableInterestsFromMailchimp() {

    $sync = new CRM_Mailchimp_Sync(static::$test_list_id);
    $cases = [
      // 'Normal' tests
      [ (object) ['interestId1' => TRUE, 'interestId2'=>TRUE], ['interestId1'=>TRUE, 'interestId2'=>TRUE]],
      [ (object) ['interestId1' => FALSE, 'interestId2'=>TRUE], ['interestId1'=>FALSE, 'interestId2'=>TRUE]],
      // Test that if Mailchimp omits an interest grouping we've mapped it's
      // considered false. This wil be the case if someone deletes an interest
      // on Mailchimp but not the mapped group in Civi.
      [ (object) ['interestId1' => TRUE], ['interestId1'=>TRUE, 'interestId2'=>FALSE]],
      // Test that non-mapped interests are ignored.
      [ (object) ['interestId1' => TRUE, 'foo' => TRUE], ['interestId1'=>TRUE, 'interestId2'=>FALSE]],
      ];
    foreach ($cases as $i=>$_) {
      list($input, $expected) = $_;
      $ints = $sync->getComparableInterestsFromMailchimp($input);
      $this->assertEquals($expected, $ints, "mapping failed for test '$i'");
    }

  }
  /**
   * Check the right calls are made to the Mailchimp API.
   *
   * @depends testGetMCInterestGroupings
   */
  public function testPostHookForMembershipListChanges() {

    // Get Mock API.
    $api_prophecy = $this->prophesize('CRM_Mailchimp_Api3');
    CRM_Mailchimp_Utils::setMailchimpApi($api_prophecy->reveal());

    // handy copy.
    $subscriber_hash = static::$civicrm_contact_1['subscriber_hash'];

    //
    // Test:
    //
    // If someone is added to the CiviCRM group, then we should expect them to
    // get subscribed.

    // Prepare the mock for the syncSingleContact
    // We expect that a PUT request is sent to Mailchimp.
    $api_prophecy->put("/lists/dummylistid/members/$subscriber_hash",
      Argument::that(function($_){
        return $_['status'] == 'subscribed'
          && $_['interests']['interestId1'] === FALSE
          && $_['interests']['interestId2'] === FALSE
          && count($_['interests']) == 2;
      }))
      ->shouldBeCalled();

    $result = civicrm_api3('GroupContact', 'create', [
      'sequential' => 1,
      'group_id' => static::$civicrm_group_id_membership,
      'contact_id' => static::$civicrm_contact_1['contact_id'],
      'status' => "Added",
    ]);


    //
    // Test:
    //
    // If someone is removed or deleted from the CiviCRM group they should get
    // removed from Mailchimp.

    // Prepare the mock for the syncSingleContact - this should get called
    // twice.
    $api_prophecy->patch("/lists/dummylistid/members/$subscriber_hash", ['status' => 'unsubscribed'])
      ->shouldbecalledTimes(2);

    $result = civicrm_api3('GroupContact', 'create', [
      'sequential' => 1,
      'group_id' => static::$civicrm_group_id_membership,
      'contact_id' => static::$civicrm_contact_1['contact_id'],
      'status' => "Removed",
    ]);

    //
    // Test:
    //
    // If someone is deleted from the CiviCRM group they should get removed from
    // Mailchimp.
    $result = civicrm_api3('GroupContact', 'delete', [
      'group_id' => static::$civicrm_group_id_membership,
      'contact_id' => static::$civicrm_contact_1['contact_id'],
    ]);


  }
  /**
   * Check the right calls are made to the Mailchimp API as result of
   * adding/removing/deleting someone from an group linked to an interest
   * grouping.
   *
   * @depends testGetMCInterestGroupings
   */
  public function testPostHookForInterestGroupChanges() {

    // Get Mock API.
    $api_prophecy = $this->prophesize('CRM_Mailchimp_Api3');
    CRM_Mailchimp_Utils::setMailchimpApi($api_prophecy->reveal());

    $subscriber_hash = static::$civicrm_contact_1['subscriber_hash'];

    //
    // Test:
    //
    // Because this person is NOT on the membership list, nothing we do to their
    // interest group membership should result in a Mailchimp update.
    //
    // Prepare the mock for the syncSingleContact
    $api_prophecy->put("/lists/dummylistid/members/$subscriber_hash", Argument::any())->shouldNotBeCalled();

    $result = civicrm_api3('GroupContact', 'create', [
      'sequential' => 1,
      'group_id' => static::$civicrm_group_id_interest_1,
      'contact_id' => static::$civicrm_contact_1['contact_id'],
      'status' => "Added",
    ]);
    $result = civicrm_api3('GroupContact', 'create', [
      'sequential' => 1,
      'group_id' => static::$civicrm_group_id_interest_1,
      'contact_id' => static::$civicrm_contact_1['contact_id'],
      'status' => "Removed",
    ]);
    $result = civicrm_api3('GroupContact', 'delete', [
      'sequential' => 1,
      'group_id' => static::$civicrm_group_id_interest_1,
      'contact_id' => static::$civicrm_contact_1['contact_id'],
    ]);

    //
    // Test:
    //
    // Add them to the membership group, then these interest changes sould
    // result in an update.

    // Create a new prophecy since we used the last one to assert something had
    // not been called.
    $api_prophecy = $this->prophesize('CRM_Mailchimp_Api3');
    CRM_Mailchimp_Utils::setMailchimpApi($api_prophecy->reveal());

    // Prepare the mock for the syncSingleContact
    // We expect that a PUT request is sent to Mailchimp.
    $api_prophecy->put("/lists/dummylistid/members/$subscriber_hash",
      Argument::that(function($_){
        return $_['status'] == 'subscribed'
          && $_['interests']['interestId1'] === FALSE
          && $_['interests']['interestId2'] === FALSE
          && count($_['interests']) == 2;
      }))
      ->shouldBeCalled();

    $result = civicrm_api3('GroupContact', 'create', [
      'sequential' => 1,
      'group_id' => static::$civicrm_group_id_membership,
      'contact_id' => static::$civicrm_contact_1['contact_id'],
      'status' => "Added",
    ]);

    // Use new prophecy
    $api_prophecy = $this->prophesize('CRM_Mailchimp_Api3');
    CRM_Mailchimp_Utils::setMailchimpApi($api_prophecy->reveal());
    $api_prophecy->put("/lists/dummylistid/members/$subscriber_hash", Argument::any())->shouldBeCalledTimes(3);

    $result = civicrm_api3('GroupContact', 'create', [
      'sequential' => 1,
      'group_id' => static::$civicrm_group_id_interest_1,
      'contact_id' => static::$civicrm_contact_1['contact_id'],
      'status' => "Added",
    ]);
    $result = civicrm_api3('GroupContact', 'create', [
      'sequential' => 1,
      'group_id' => static::$civicrm_group_id_interest_1,
      'contact_id' => static::$civicrm_contact_1['contact_id'],
      'status' => "Removed",
    ]);
    $result = civicrm_api3('GroupContact', 'delete', [
      'sequential' => 1,
      'group_id' => static::$civicrm_group_id_interest_1,
      'contact_id' => static::$civicrm_contact_1['contact_id'],
    ]);

    // Finally delete the membership list group link to re-set the fixture.
    $api_prophecy = $this->prophesize('CRM_Mailchimp_Api3');
    CRM_Mailchimp_Utils::setMailchimpApi($api_prophecy->reveal());
    $api_prophecy->patch("/lists/dummylistid/members/$subscriber_hash", Argument::any())->shouldBeCalled();
    $result = civicrm_api3('GroupContact', 'delete', [
      'group_id' => static::$civicrm_group_id_membership,
      'contact_id' => static::$civicrm_contact_1['contact_id'],
    ]);

  }
  /**
   * Checks that multiple updates do not trigger syncs.
   *
   * We run the testGetMCInterestGroupings first as it caches data this depends
   * on.
   * @depends testGetMCInterestGroupings
   */
  public function testPostHookDoesNotRunForBulkUpdates() {

    // Get Mock API.
    $api_prophecy = $this->prophesize('CRM_Mailchimp_Api3');
    CRM_Mailchimp_Utils::setMailchimpApi($api_prophecy->reveal());

    $api_prophecy->put()->shouldNotBeCalled();
    $api_prophecy->patch()->shouldNotBeCalled();
    $api_prophecy->get()->shouldNotBeCalled();
    $api_prophecy->post()->shouldNotBeCalled();
    $api_prophecy->delete()->shouldNotBeCalled();

    // Array of ContactIds - provide 2.
    $objectRef = [static::$civicrm_contact_1['contact_id'], 1];
    mailchimp_civicrm_post('create', 'GroupContact', $objectId=static::$civicrm_group_id_membership, $objectRef );
  }
  /**
   * Tests the selection of email address.
   *
   * 1. Check initial email is picked up.
   * 2. Check that a bulk one is preferred, if exists.
   * 3. Check that a primary one is used bulk is on hold.
   * 4. Check that a primary one is used if no bulk one.
   * 5. Check that secondary, not bulk, not primary one is NOT used.
   * 6. Check that a not bulk, not primary one is used if all else fails.
   * 7. Check contact not selected if all emails on hold
   * 8. Check contact not selected if opted out
   * 9. Check contact not selected if 'do not email' is set
   * 10. Check contact not selected if deceased.
   *
   * @depends testGetMCInterestGroupings
   */
  public function testCollectCiviUsesRightEmail() {

    $subscriber_hash = static::$civicrm_contact_1['subscriber_hash'];

    // Prepare the mock for the subscription the post hook will do.
    $api_prophecy = $this->prophesize('CRM_Mailchimp_Api3');
    CRM_Mailchimp_Utils::setMailchimpApi($api_prophecy->reveal());
    $api_prophecy->put("/lists/dummylistid/members/$subscriber_hash", Argument::any());
    $this->joinMembershipGroup(static::$civicrm_contact_1);
    $sync = new CRM_Mailchimp_Sync(static::$test_list_id);

    //
    // Test 1:
    //
    $sync->collectCiviCrm('push');
    // Should have one person in it.
    $this->assertEquals(1, $sync->countCiviCrmMembers());
    $dao = CRM_Core_DAO::executeQuery("SELECT email FROM tmp_mailchimp_push_c");
    $dao->fetch();
    // Check email is what we'd expect.
    $this->assertEquals(static::$civicrm_contact_1['email'], $dao->email);

    //
    // Test 2:
    //
    // Now add another email, this one is bulk.
    // Nb. adding a bulk email removes the is_bulkmail flag from other email
    // records.
    $second_email = civicrm_api3('Email', 'create', [
      'contact_id' => static::$civicrm_contact_1['contact_id'],
      'email' => static::$civicrm_contact_2['email'],
      'is_bulkmail' => 1,
      'sequential' => 1,
      ]);
    if (empty($second_email['id'])) {
      throw new Exception("Well this shouldn't happen. No Id for created email.");
    }
    $sync->collectCiviCrm('push');
    // Should have one person in it.
    $this->assertEquals(1, $sync->countCiviCrmMembers());
    $dao = CRM_Core_DAO::executeQuery("SELECT email FROM tmp_mailchimp_push_c");
    $dao->fetch();
    // Check email is what we'd expect.
    $this->assertEquals(static::$civicrm_contact_2['email'], $dao->email);

    //
    // Test 3:
    //
    // Set the bulk one to on hold.
    //
    civicrm_api3('Email', 'create', ['id' => $second_email['id'], 'on_hold' => 1]);
    $sync->collectCiviCrm('push');
    $this->assertEquals(1, $sync->countCiviCrmMembers());
    $dao = CRM_Core_DAO::executeQuery("SELECT email FROM tmp_mailchimp_push_c");
    $dao->fetch();
    // Check email is what we'd expect.
    $this->assertEquals(static::$civicrm_contact_1['email'], $dao->email);

    //
    // Test 4:
    //
    // Delete the bulk one; should now fallback to primary.
    //
    civicrm_api3('Email', 'delete', ['id' => $second_email['id']]);
    $sync->collectCiviCrm('push');
    // Should have one person in it.
    $this->assertEquals(1, $sync->countCiviCrmMembers());
    $dao = CRM_Core_DAO::executeQuery("SELECT email FROM tmp_mailchimp_push_c");
    $dao->fetch();
    // Check email is what we'd expect.
    $this->assertEquals(static::$civicrm_contact_1['email'], $dao->email);

    //
    // Test 5:
    //
    // Add a not bulk, not primary one. This should NOT get used.
    //
    $second_email = civicrm_api3('Email', 'create', [
      'contact_id' => static::$civicrm_contact_1['contact_id'],
      'email' => static::$civicrm_contact_2['email'],
      'is_bulkmail' => 0,
      'is_primary' => 0,
      'sequential' => 1,
      ]);
    if (empty($second_email['id'])) {
      throw new Exception("Well this shouldn't happen. No Id for created email.");
    }
    $sync->collectCiviCrm('push');
    $this->assertEquals(1, $sync->countCiviCrmMembers());
    $dao = CRM_Core_DAO::executeQuery("SELECT email FROM tmp_mailchimp_push_c");
    $dao->fetch();
    // Check email is what we'd expect.
    $this->assertEquals(static::$civicrm_contact_1['email'], $dao->email);

    //
    // Test 6:
    //
    // Check that an email is selected, even if there's no primary and no bulk.
    //
    // Find the primary email and delete it.
    $result = civicrm_api3('Email', 'get', [
      'contact_id' => static::$civicrm_contact_1['contact_id'],
      'email' => static::$civicrm_contact_1['email'],
      'api.Email.delete' => ['id' => '$value.id']
    ]);

    $sync->collectCiviCrm('push');
    // Should have one person in it.
    $this->assertEquals(1, $sync->countCiviCrmMembers());
    $dao = CRM_Core_DAO::executeQuery("SELECT email FROM tmp_mailchimp_push_c");
    $dao->fetch();
    // Check email is what we'd expect.
    $this->assertEquals(static::$civicrm_contact_2['email'], $dao->email);

    //
    // Test 7
    //
    // Check that if all emails are on hold, user is not selected.
    //
    civicrm_api3('Email', 'create', ['id' => $second_email['id'], 'on_hold' => 1]);
    $sync->collectCiviCrm('push');
    $this->assertEquals(0, $sync->countCiviCrmMembers());

    //
    // Test 8
    //
    // Check that even with a bulk, primary email, contact is not selected if
    // they have opted out.
    civicrm_api3('Email', 'create', ['id' => $second_email['id'],
      'email' => static::$civicrm_contact_1['email'],
      'on_hold' => 0,
    ]);
    civicrm_api3('Contact', 'create', [
      'contact_id' => static::$civicrm_contact_1['contact_id'],
      'is_opt_out' => 1,
    ]);
    $sync->collectCiviCrm('push');
    $this->assertEquals(0, $sync->countCiviCrmMembers());


    //
    // Test 9
    //
    // Check that even with a bulk, primary email, contact is not selected if
    // they have do_not_email
    civicrm_api3('Contact', 'create', [
      'contact_id' => static::$civicrm_contact_1['contact_id'],
      'is_opt_out' => 0,
      'do_not_email' => 1,
    ]);
    $sync->collectCiviCrm('push');
    $this->assertEquals(0, $sync->countCiviCrmMembers());


    //
    // Test 10
    //
    // Check that even with a bulk, primary email, contact is not selected if
    // they is_deceased
    civicrm_api3('Contact', 'create', [
      'contact_id' => static::$civicrm_contact_1['contact_id'],
      'do_not_email' => 0,
      'is_deceased' => 1,
    ]);
    $sync->collectCiviCrm('push');
    $this->assertEquals(0, $sync->countCiviCrmMembers());

  }
}
