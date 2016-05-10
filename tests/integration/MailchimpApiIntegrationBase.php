<?php
/**
 * @file
 * Contains code for generating fixtures shared between tests.
 */
class MailchimpApiIntegrationBase extends \PHPUnit_Framework_TestCase {
  const MC_TEST_LIST_NAME = 'Mailchimp-CiviCRM Integration Test List';
  const MC_INTEREST_CATEGORY_TITLE = 'Test Interest Category';
  const MC_INTEREST_NAME_1 = 'Orang-utans';
  const MC_INTEREST_NAME_2 = 'Climate Change';
  const C_TEST_MEMBERSHIP_GROUP_NAME = 'mailchimp_integration_test_m';
  const C_TEST_INTEREST_GROUP_NAME_1 = 'mailchimp_integration_test_i1';
  const C_TEST_INTEREST_GROUP_NAME_2 = 'mailchimp_integration_test_i2';
  protected static $api_contactable;
  /** string holds the Mailchimp Id for our test list. */
  protected static $test_list_id;
  /** string holds the Mailchimp Id for test interest category. */
  protected static $test_interest_category_id;
  /** string holds the Mailchimp Id for test interest. */
  protected static $test_interest_id_1;
  /** string holds the Mailchimp Id for test interest. */
  protected static $test_interest_id_2;

  /** holds CiviCRM contact Id for test contact 1*/
  protected static $test_cid1;
  /** holds CiviCRM contact Id for test contact 2*/
  protected static $test_cid2;
  /** holds CiviCRM Group Id for membership group*/
  protected static $civicrm_group_id_membership;
  /** holds CiviCRM Group Id for interest group id*/
  protected static $civicrm_group_id_interest_1;
  /** holds CiviCRM Group Id for interest group id*/
  protected static $civicrm_group_id_interest_2;

  /**
   * array Test contact 1
   */
  protected static $civicrm_contact_1 = [
    'contact_id' => NULL,
    'first_name' => 'Wilma',
    'last_name' => 'Flintstone-Test-Record',
    ];


  /**
   * Connect to API and create test fixture lists.
   */
  public static function setUpBeforeClass() {
  }
  /**
   * Connect to API and create test fixture lists.
   */
  public static function createMailchimpFixtures() {
    try {
      $api = CRM_Mailchimp_Utils::getMailchimpApi();
      $result = $api->get('/');
      static::$api_contactable = $result;

      // Ensure we have a test list.
      $test_list_id = NULL;
      $lists = $api->get('/lists', ['count' => 10000, 'fields' => 'lists.name,lists.id'])->data->lists;
      foreach ($lists as $list) {
        if ($list->name == self::MC_TEST_LIST_NAME) {
          $test_list_id = $list->id;
          break;
        }
      }

      if (empty($test_list_id)) {
        // Test list does not exist, create it now.

        // Annoyingly Mailchimp uses addr1 in a GET / response and address1 for
        // a POST /lists request!
        $contact = (array) static::$api_contactable->data->contact;
        $contact['address1'] = $contact['addr1'];
        $contact['address2'] = $contact['addr2'];
        unset($contact['addr1'], $contact['addr2']);

        $test_list_id = $api->post('/lists', [
          'name' => self::MC_TEST_LIST_NAME,
          'contact' => $contact,
          'permission_reminder' => 'This is sent to test email accounts only.',
          'campaign_defaults' => [
            'from_name' => 'Automated Test Script',
            'from_email' => static::$api_contactable->data->email,
            'subject' => 'Automated Test',
            'language' => 'en',
            ],
          'email_type_option' => FALSE,
        ])->data->id;
      }

      // Store this for our fixture.
      static::$test_list_id = $test_list_id;

      // Ensure the list has the interest category we need.
      $categories = $api->get("/lists/$test_list_id/interest-categories",
            ['fields' => 'categories.id,categories.title','count'=>10000])
          ->data->categories;
      $category_id = NULL;
      foreach ($categories as $category) {
        if ($category->title == static::MC_INTEREST_CATEGORY_TITLE) {
          $category_id = $category->id;
        }
      }
      if ($category_id === NULL) {
        // Create it.
        $category_id = $api->post("/lists/$test_list_id/interest-categories", [
          'title' => static::MC_INTEREST_CATEGORY_TITLE,
          'type' => 'hidden',
        ])->data->id;
      }
      static::$test_interest_category_id = $category_id;

      // Store thet interest ids.
      static::$test_interest_id_1 = static::createInterest(static::MC_INTEREST_NAME_1);
      static::$test_interest_id_2 = static::createInterest(static::MC_INTEREST_NAME_2);
    }
    catch (CRM_Mailchimp_Exception $e) {
      // Spit out request and response for debugging.
      print "Request:\n";
      print_r($e->request);
      print "Response:\n";
      print_r($e->response);
      // re-throw exception.
      throw $e;
    }
  }
  /**
   * Create an interest within our interest category on the Mailchimp list.
   *
   * @return string interest_id created.
   */
  public static function createInterest($name) {
    $api = CRM_Mailchimp_Utils::getMailchimpApi();
    // Ensure the interest category has the interests we need.
    $test_list_id = static::$test_list_id;
    $category_id = static::$test_interest_category_id;
    $interests = $api->get("/lists/$test_list_id/interest-categories/$category_id/interests",
      ['fields' => 'interests.id,interests.name','count'=>10000])
      ->data->interests;
    $interest_id = NULL;
    foreach ($interests as $interest) {
      if ($interest->name == $name) {
        $interest_id = $interest->id;
      }
    }
    if ($interest_id === NULL) {
      // Create it.
      // Note: as of 9 May 2016, Mailchimp do not advertise this method and
      // while it works, it throws an error. They confirmed this behaviour in
      // a live chat session and said their devs would look into it, so may
      // have been fixed.
      try {
        $interest_id = $api->post("/lists/$test_list_id/interest-categories/$category_id/interests", [
          'name' => $name,
        ])->data->id;
      }
      catch (CRM_Mailchimp_NetworkErrorException $e) {
        // As per comment above, this may still have worked. Repeat the
        // lookup.
        //
        $interests = $api->get("/lists/$test_list_id/interest-categories/$category_id/interests",
          ['fields' => 'interests.id,interests.name','count'=>10000])
          ->data->interests;
        foreach ($interests as $interest) {
          if ($interest->name == $name) {
            $interest_id = $interest->id;
          }
        }
        if (empty($interest_id)) {
          throw new CRM_Mailchimp_NetworkErrorException($api, "Creating the interest failed, and while this is a known bug, it actually did not create the interest, either. ");
        }
      }
    }
    return $interest_id;
  }
  /**
   * Connect to API and create test fixture lists.
   */
  public static function createCiviCrmFixtures() {

    //
    // Now set up the CiviCRM fixtures.
    //

    // Need to know field Ids for mailchimp fields.
    $result = civicrm_api3('CustomField', 'get', ['label' => array('LIKE' => "%mailchimp%")]);
    $custom_ids = [];
    foreach ($result['values'] as $custom_field) {
      $custom_ids[$custom_field['name']] = "custom_" . $custom_field['id'];
    }
    // Ensure we have the fields we later rely on.
    foreach (['Mailchimp_Group', 'Mailchimp_Grouping', 'Mailchimp_List', 'is_mc_update_grouping'] as $_) {
      if (empty($custom_ids[$_])) {
        throw new Exception("Expected to find the Custom Field with name $_");
      }
    }

    // Next create mapping groups in CiviCRM for membership group
    $result = civicrm_api3('Group', 'get', ['name' => static::C_TEST_MEMBERSHIP_GROUP_NAME, 'sequential' => 1]);
    if ($result['count'] == 0) {
      // Didn't exist, create it now.
      $result = civicrm_api3('Group', 'create', [
        'sequential' => 1,
        'name' => static::C_TEST_MEMBERSHIP_GROUP_NAME,
        'title' => static::C_TEST_MEMBERSHIP_GROUP_NAME,
      ]);
    }
    static::$civicrm_group_id_membership = (int) $result['values'][0]['id'];

    // Ensure this group is set to be the membership group.
    $result = civicrm_api3('Group', 'create', array(
      'id' => static::$civicrm_group_id_membership,
      $custom_ids['Mailchimp_List'] => static::$test_list_id,
      $custom_ids['is_mc_update_grouping'] => 0,
      $custom_ids['Mailchimp_Grouping'] => NULL,
      $custom_ids['Mailchimp_Group'] => NULL,
    ));

    // Create group for the interests
    static::$civicrm_group_id_interest_1 = (int) static::createMappedInterestGroup($custom_ids, static::C_TEST_INTEREST_GROUP_NAME_1, static::$test_interest_id_1);
    static::$civicrm_group_id_interest_2 = (int) static::createMappedInterestGroup($custom_ids, static::C_TEST_INTEREST_GROUP_NAME_2, static::$test_interest_id_2);


    // Now create test contact 1
    $domain = preg_replace('@^https?://([^/]+).*$@', '$1', CIVICRM_UF_BASEURL);
    $email = strtolower(static::$civicrm_contact_1['first_name'] . '.' . static::$civicrm_contact_1['last_name'])
      . '@' . $domain;
    static::$civicrm_contact_1['email'] = $email;
    static::$civicrm_contact_1['subscriber_hash'] = md5(strtolower($email));
    $result = civicrm_api3('Contact', 'get', ['sequential' => 1,
      'first_name' => static::$civicrm_contact_1['first_name'],
      'last_name'  => static::$civicrm_contact_1['last_name'],
      'email'      => $email,
      ]);

    if ($result['count'] == 0) {
      print "Creating contact...\n";
      // Create the contact.
      $result = civicrm_api3('Contact', 'create', ['sequential' => 1,
        'contact_type' => 'Individual',
        'first_name' => static::$civicrm_contact_1['first_name'],
        'last_name'  => static::$civicrm_contact_1['last_name'],
        'email'      => $email,
      ]);
    }
    static::$civicrm_contact_1['contact_id'] = (int) $result['values'][0]['id'];
  }
  /**
   * Create a group in CiviCRM that maps to the interest group name.
   *
   * @param string $name e.g. C_TEST_INTEREST_GROUP_NAME_1
   * @param string $interest_id Mailchimp interest id.
   */
  public static function createMappedInterestGroup($custom_ids, $name, $interest_id) {

    // Create group for the interest.
    $result = civicrm_api3('Group', 'get', ['name' => $name, 'sequential' => 1]);
    if ($result['count'] == 0) {
      // Didn't exist, create it now.
      $result = civicrm_api3('Group', 'create', [ 'sequential' => 1, 'name' => $name, 'title' => $name, ]);
    }
    $group_id = (int) $result['values'][0]['id'];

    // Ensure this group is set to be the interest group.
    $result = civicrm_api3('Group', 'create', [
      'id'                                 => $group_id,
      $custom_ids['Mailchimp_List']        => static::$test_list_id,
      $custom_ids['is_mc_update_grouping'] => 0,
      $custom_ids['Mailchimp_Grouping']    => static::$test_interest_category_id,
      $custom_ids['Mailchimp_Group']       => $interest_id,
    ]);

    return $group_id;
  }
  /**
   * Remove the test list, if one was successfully set up.
   */
  public static function tearDownAfterClass() {
  }
  /**
   * Remove the test list, if one was successfully set up.
   */
  public static function tearDownMailchimpFixtures() {
    if (empty(static::$api_contactable->http_code)
      || static::$api_contactable->http_code != 200
      || empty(static::$test_list_id)
      || !is_string(static::$test_list_id)) {

      // Nothing to do.
      return;
    }

    try {

      // Delete is a bit of a one-way thing so we really test that it's the
      // right thing to do.

      // Check that the list exists, is named as we expect and only has max 2
      // contacts.
      $api = CRM_Mailchimp_Utils::getMailchimpApi();
      $test_list_id = static::$test_list_id;
      $result = $api->get("/lists/$test_list_id", ['fields' => '']);
      if ($result->http_code != 200) {
        throw new CRM_Mailchimp_RequestErrorException($api, "Trying to delete test list $test_list_id but getting list details failed. ");
      }
      if ($result->data->id != $test_list_id) {
        // OK this is paranoia.
        throw new CRM_Mailchimp_RequestErrorException($api, "Trying to delete test list $test_list_id but getting list returned different list?! ");
      }
      if ($result->data->name != static::MC_TEST_LIST_NAME) {
        // OK this is paranoia.
        throw new CRM_Mailchimp_RequestErrorException($api, "Trying to delete test list $test_list_id but the name was not as expected, so not deleted. ");
      }
      if ($result->data->stats->member_count > 2) {
        // OK this is paranoia.
        throw new CRM_Mailchimp_RequestErrorException($api, "Trying to delete test list $test_list_id but it has more than 2 members, so not deleted. ");
      }

      // OK, the test list exists, has the right name and only has two members:
      // delete it.
      $result = $api->delete("/lists/$test_list_id");
      if ($result->http_code != 204) {
        throw new CRM_Mailchimp_RequestErrorException($api, "Trying to delete test list $test_list_id but delete method did not return 204 as http response. ");
      }

    }
    catch (CRM_Mailchimp_Exception $e) {
      print "*** Exception!***\n" . $e->getMessage() . "\n";
      // Spit out request and response for debugging.
      print "Request:\n";
      print_r($e->request);
      print "Response:\n";
      print_r($e->response);
      // re-throw exception for usual stack trace etc.
      throw $e;
    }
  }
  /**
   * Remove the test list, if one was successfully set up.
   */
  public static function tearDownCiviCrmFixtures() {
    // CiviCRM teardown.
    //
    // Delete test contacts
    if (!empty(static::$civicrm_contact_1['contact_id'])) {
      print "Deleting test contact " . static::$civicrm_contact_1['contact_id'] . "\n";
      $contact_id = (int) static::$civicrm_contact_1['contact_id'];
      if ($contact_id>0) {
        $result = civicrm_api3('Contact', 'delete', [
          'id' => $contact_id,
          'skip_undelete' => 1,
        ]);
      }
    }
    // Delete test group(s)
    if (static::$civicrm_group_id_membership) {
      print "deleting test list ".static::$civicrm_group_id_membership ."\n";
      // Ensure this group is set to be the membership group.
      $result = civicrm_api3('Group', 'delete', ['id' => static::$civicrm_group_id_membership]);
    }

  }
  /**
   * Check that the contact's email is a member in given state.
   *
   * @param array $contact e.g. static::$civicrm_contact_1
   * @param string $state Mailchimp member state: 'subscribed', 'unsubscribed', ...
   */
  public function assertContactExistsWithState($contact, $state) {
    $api = CRM_Mailchimp_Utils::getMailchimpApi();
    try {
      $result = $api->get("/lists/" . static::$test_list_id . "/members/$contact[subscriber_hash]", ['fields' => 'status']);
    }
    catch (CRM_Mailchimp_RequestErrorException $e) {
      if ($e->response->http_code == 404) {
        // Not subscribed give more helpful error.
        $this->fail("Expected contact $contact[email] to be in the list at Mailchimp, but MC said resource not found; i.e. not subscribed.");
      }
      throw $e;
    }
    $this->assertEquals($state, $result->data->status);
  }
  /**
   * Check that the contact's email is not a member of the test list.
   *
   * @param array $contact e.g. static::$civicrm_contact_1
   */
  public function assertContactNotListMember($contact) {
    $api = CRM_Mailchimp_Utils::getMailchimpApi();
    try {
      $subscriber_hash = static::$civicrm_contact_1['subscriber_hash'];
      $result = $api->get("/lists/" . static::$test_list_id . "/members/$contact[subscriber_hash]", ['fields' => 'status']);
    }
    catch (CRM_Mailchimp_RequestErrorException $e) {
      $this->assertEquals(404, $e->response->http_code);
    }
  }
}
