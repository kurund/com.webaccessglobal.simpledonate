<?php
/*
 +--------------------------------------------------------------------+
 | CiviCRM version 4.5                                                |
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC (c) 2004-2014                                |
 +--------------------------------------------------------------------+
 | This file is a part of CiviCRM.                                    |
 |                                                                    |
 | CiviCRM is free software; you can copy, modify, and distribute it  |
 | under the terms of the GNU Affero General Public License           |
 | Version 3, 19 November 2007 and the CiviCRM Licensing Exception.   |
 |                                                                    |
 | CiviCRM is distributed in the hope that it will be useful, but     |
 | WITHOUT ANY WARRANTY; without even the implied warranty of         |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.               |
 | See the GNU Affero General Public License for more details.        |
 |                                                                    |
 | You should have received a copy of the GNU Affero General Public   |
 | License and the CiviCRM Licensing Exception along                  |
 | with this program; if not, contact CiviCRM LLC                     |
 | at info[AT]civicrm[DOT]org. If you have questions about the        |
 | GNU Affero General Public License or the licensing of CiviCRM,     |
 | see the CiviCRM license FAQ at http://civicrm.org/licensing        |
 +--------------------------------------------------------------------+
*/

/**
 *
 * @package CRM
 * @copyright CiviCRM LLC (c) 2004-2014
 * $Id$
 *
 */

/**
 * This class generates form components for Component
 */
class CRM_SimpleDonate_Form_SimpleDonationSetting extends CRM_Admin_Form_Setting {
  /**
   * This function sets the default values for the form.
   * default values are retrieved from the database
   *
   * @access public
   *
   * @return void
   */
  function setDefaultValues() {
    $domainID = CRM_Core_Config::domainID();
    $settings = civicrm_api3('Setting', 'get', array(
      'domain_id' => $domainID,
      'return' => "simple_donation_page",
    ));
    $this->_defaults['simpleDonation'] = CRM_Utils_Array::value('simple_donation_page', $settings['values'][$domainID]);
    $this->_defaults['ziptastic'] = CRM_Core_BAO_Setting::getItem('Simple Donation', 'ziptastic_enable');
    return $this->_defaults;
  }

  /**
   * Function to build the form
   *
   * @return void
   * @access public
   */
  public function buildQuickForm() {
    CRM_Utils_System::setTitle(ts('Settings - Simple donate form'));
    $attributes = array(
      'entity' => 'Contribution',
      'field' => 'contribution_page_id',
      'option_url' => NULL,
    );

    $this->addSelect('simpleDonation', $attributes, TRUE);
    $this->addElement('checkbox', "ziptastic", ts('Is Ziptastic enabled?'));
    parent::buildQuickForm();
  }

  public function postProcess() {
    $params = $this->controller->exportValues($this->_name);
    $donationParams = array(
      'domain_id' => CRM_Core_Config::domainID(),
      'simple_donation_page' => $params['simpleDonation'],
    );
    $result = civicrm_api3('setting', 'create', $donationParams);
    if (CRM_Utils_Array::value('is_error', $result, FALSE)) {
      CRM_Core_Error::debug_var('setting-create result for angular_donation', $result);
      throw new CRM_Core_Exception('Failed to create settings for angular_donation');
    }

    $zipParams = array(
      'domain_id' => CRM_Core_Config::domainID(),
      'ziptastic_enable' => CRM_Utils_Array::value('ziptastic', $params) ? 1 : 0,
    );
    $result = civicrm_api3('setting', 'create', $zipParams);
    if (CRM_Utils_Array::value('is_error', $result, FALSE)) {
      CRM_Core_Error::debug_var('setting-create result for angular_donation', $result);
      throw new CRM_Core_Exception('Failed to create settings for angular_donation');
    }
  }

  public function transactDonation() {
    $session = CRM_Core_Session::singleton();
    $contactID = $session->get('userID');
    $params = $_POST['params'];

    //check credit card expiry validation
    if (!empty($params["credit"])) {
      $cardExpiryMonth = substr($params['cardExpiry'],0,2);
      $cardExpiryYear = substr($params['cardExpiry'],2);
      $currentYear = date("y");
      $errorList =array();
      if ($cardExpiryYear < $currentYear) {
        $errorList['cardExpiryError'] = "Card is Expired";
        echo json_encode($errorList);
        exit;
      }
      else if ($cardExpiryYear == $currentYear && $cardExpiryMonth < date("m")) {
        $errorList['cardExpiryError'] = "Card is Expired";
        echo json_encode($errorList);
        exit;
      }
    }
    $params['amount'] = $_POST['amount'];
    $creditInfo = $_POST['creditInfo'];
    $isTest = $_POST['isTest'];
    //create Contact, billing address
    $userInfo = explode(' ', $params['user']);
    $cParam = array(
      'email' => $params['email'],
      'first_name' =>$userInfo[0],
      'last_name' =>$userInfo[1],
      'contact_type' => 'Individual'
    );
    $address =array(
      'location_type_id' => 'Billing',
      'street_address'=> $params['address'],
      'city'=> $params['city'],
      'state_province_id'=> $params['state'],
      'country_id' => $params['country'],
      'postal_code'=> $params['zip'],
      'name' => $userInfo[0].' '.$userInfo[1],
    );
    $email = array(
      'email' => $params['email'],
      'location_type_id' => 'Billing',
    );

    if (!$contactID) {
      //Check if contact exist using dedup rule
      $dupeParams = $cParam;
      $dedupeParams = CRM_Dedupe_Finder::formatParams($dupeParams, 'Individual');
      $dedupeParams['check_permission'] = FALSE;
      $ids = CRM_Dedupe_Finder::dupesByParams($dedupeParams, 'Individual');
      // if we find more than one contact, use the first one
      $contactID = CRM_Utils_Array::value(0, $ids);
      if (!$contactID) {
        $cont = civicrm_api3('Contact', 'create', $cParam);
        $contactID = $cont['id'];
      }
      $params['contact_id'] = $contactID;
    }

    if ($contactID) {
      foreach (array('address', 'email') as $loc) {
        $result = civicrm_api3($loc, 'get', array(
          'contact_id' => $contactID,
          'location_type_id' => 'Billing',
        ));
        // Use first id if we have any results
        if (!empty($result['values'])) {
          $ids = array_keys($result['values']);
          ${$loc}['id'] = $ids[0];
        }
      }
      $address['contact_id'] = $email['contact_id'] = $contactID;
      civicrm_api3('address', 'create', $address);
      civicrm_api3('email', 'create', $email);
    }

    $donation = self::createSimpleContribution($contactID, $params, $isTest, $creditInfo);
    echo json_encode($donation);
    CRM_Utils_System::civiExit();
  }

  public function createSimpleContribution($contactID, $params, $isTest, $creditInfo) {
    $locationTypes = CRM_Core_PseudoConstant::get('CRM_Core_DAO_Address', 'location_type_id', array(), 'validate');
    $bltID = array_search('Billing', $locationTypes);
    $settings = civicrm_api3('Setting', 'get', array(
      'domain_id' => $domainID,
      'return' => "simple_donation_page",
    ));
    $donatePageID = $settings['values'][$domainID]['simple_donation_page'];
    $donateConfig = $donatePage = civicrm_api3('ContributionPage', 'getsingle', array(
      'id' => $donatePageID,
    ));
    $contributionparams = array();
    $isrecur = $params['recur'];

    $contributionparams = array(
      "billing_first_name" => $params['first_name'],
      "first_name" => $params['first_name'],
      "billing_middle_name" => $params['middle_name'],
      "middle_name" => $params['middle_name'],
      "billing_last_name" => $params['last_name'],
      "last_name" => $params['last_name'],
      "billing_street_address-{$bltID}" => $params['address'],
      "street_address" => $params['address'],
      "billing_city-{$bltID}" => $params['city'],
      "city" => $params['city'],
      "billing_country_id-{$bltID}" => $params['country'],
      "country_id" => $params['country'],
      "billing_state_province_id-{$bltID}" => $params['state'] ,
      "state_province_id" => $params['state'],
      "billing_postal_code-{$bltID}" => $params['zip'],
      "postal_code" => $params['zip'],
      "year" => "20".substr($params['cardExpiry'], 2, 3),
      "month" => substr($params['cardExpiry'], 0, 2),
      "email" => $params['email'],
      "contribution_page_id" => $donatePageID,
      "payment_processor_id" => CRM_Utils_Array::value('payment_processor', $params),
      "is_test" => $isTest,
      "is_pay_later" => $params['is_pay_later'],
      "total_amount"=> $params['amount'],
      "financial_type_id" => $donateConfig['financial_type_id'],
      "currencyID" => $donateConfig['currency'],
      "currency" => $donateConfig['currency'],
      "skipLineItem" => 0,
      "skipRecentView" => 1,
      'is_email_receipt' => 1,
      "contact_id" => $contactID,
      "source" => "Online Contribution: {$donateConfig['title']}",
    );
    if ($params['is_pay_later']) {
      $contributionparams["contribution_status_id"] =  CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Pending');
      $contributionparams["payment_processor_id"] = 1;
    }
    if (!empty($creditInfo)) {
      $contributionparams['credit_card_number'] = $creditInfo['credit_card_number'];
      $contributionparams['cvv2'] = $creditInfo['cvv2'];
      $contributionparams['credit_card_type'] = $creditInfo['credit_card_type'];
    }
    if (!empty($debitInfo)) {
      $contributionparams['bank_identification_number'] = $creditInfo['credit_card_number'];
      $contributionparams['bank_name'] = $creditInfo['cvv2'];
      $contributionparams['bank_account_number'] = $creditInfo['credit_card_type'];
      $contributionparams['payment_type'] = $creditInfo['payment_type'];
      $contributionparams['account_holder'] = $creditInfo['account_holder'];
    }
    //unset the billing parameters if it is pay later mode
    //to avoid creation of billing location
    if ($params['is_pay_later']) {
      $billingFields = array(
        'billing_first_name',
        'billing_middle_name',
        'billing_last_name',
        "billing_street_address-{$bltID}",
        "billing_city-{$bltID}",
        "billing_state_province-{$bltID}",
        "billing_state_province_id-{$bltID}",
        "billing_postal_code-{$bltID}",
        "billing_country-{$bltID}",
        "billing_country_id-{$bltID}",
      );
      foreach ($billingFields as $value) {
        unset($contributionparams[$value]);
      }
    }
    //check for recurring contribution
    if ($isrecur) {
      $recurParams = array('contact_id' => $contactID);
      $recurParams['amount'] =  $params['amount'];
      $recurParams['auto_renew'] = 1;
      $recurParams['frequency_unit'] = 'month';
      $recurParams['frequency_interval'] = 1;
      $recurParams['financial_type_id'] = $donateConfig['financial_type_id'];
      $recurParams['is_test'] = $isTest;
      $recurParams['start_date'] = $recurParams['create_date'] = $recurParams['modified_date'] = date('YmdHis');
      $recurParams['invoice_id'] = $recurParams['trxn_id'] = md5(uniqid(rand(), TRUE));
      $recurParams['contribution_status_id'] = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Pending');
      $recurParams['payment_processor_id'] = CRM_Utils_Array::value('payment_processor_id', $contributionparams);
      $recurParams['is_email_receipt'] = 1;
      //create recurring contribution record
      $recurring = CRM_Contribute_BAO_ContributionRecur::add($recurParams);
      $recurContriID = $recurring->id;
      $contributionparams['contributionRecurID'] = $recurContriID;//$contribution->id;
      $contributionparams['contribution_recur_id'] = $recurContriID;//$contribution->id;
    }
    //call transact api
    $result = civicrm_api3('Contribution', 'transact', $contributionparams);

    if ($result['error']) {
      //make sure to cleanup db for recurring case.
      if ($recurContriID) {
        CRM_Contribute_BAO_ContributionRecur::deleteRecurContribution($recurContriID);
      }
      CRM_Core_Session::setStatus($result['error'], ts('Error'), 'error');
      return false;
    }
    else {
      $contributionID = $result['id'];
      // Send receipt
      // send recurring Notification email for user
      if ($recurContriID) {
        CRM_Contribute_BAO_ContributionPage::recurringNotify( CRM_Core_Payment::RECURRING_PAYMENT_START,
          $contactID,
          $donatePageID,
          $recurring
        );
      }
      civicrm_api3('contribution', 'sendconfirmation', array('id' => $contributionID) + $donateConfig);
      return $result;
    }
  }
}
