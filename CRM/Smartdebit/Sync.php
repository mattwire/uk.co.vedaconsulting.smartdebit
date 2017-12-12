<?php
/*--------------------------------------------------------------------+
 | CiviCRM version 4.7                                                |
+--------------------------------------------------------------------+
 | Copyright CiviCRM LLC (c) 2004-2017                                |
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
 +-------------------------------------------------------------------*/

/**
 * Class CRM_Smartdebit_Sync
 *
 * This is the main class responsible for the "Sync" scheduled job
 * It can also be accessed at civicrm/smartdebit/sync
 */
class CRM_Smartdebit_Sync
{
  const QUEUE_NAME = 'sm-pull';
  const END_URL = 'civicrm/smartdebit/syncsd/confirm';
  const END_PARAMS = 'state=done';
  const BATCH_COUNT = 10;

  /**
   * If $auddisIDs and $aruddIDs are not set all available AUDDIS/ARUDD records will be processed.
   *
   * @param bool $interactive
   * @param null $auddisIDs
   * @param null $aruddIDs
   * @return bool|CRM_Queue_Runner
   */
  public static function getRunner($interactive=TRUE, $auddisIDs = NULL, $aruddIDs = NULL) {
    // Reset stats
    CRM_Smartdebit_Settings::save(array('rejected_auddis' => NULL));
    CRM_Smartdebit_Settings::save(array('rejected_arudd' => NULL));

    // Setup the Queue
    $queue = CRM_Queue_Service::singleton()->create(array(
      'name'  => self::QUEUE_NAME,
      'type'  => 'Sql',
      'reset' => TRUE,
    ));

    //FIXME: Move these to task queue (but can't because they're needed to setup the queue)
    self::retrieveDailyCollectionReport();
    $smartDebitPayerContacts = self::retrievePayerContactDetails();

    foreach ($smartDebitPayerContacts as $key => $sdContact) {
      // Check if a recurring contribution exists, otherwise remove it from list for processing
      $sql = "SELECT ctrc.trxn_id FROM civicrm_contribution_recur ctrc
      INNER JOIN veda_smartdebit_collectionreports sdpayments ON sdpayments.transaction_id = ctrc.trxn_id
      WHERE ctrc.trxn_id = '{$sdContact['reference_number']}'";
      $trxn_id = CRM_Core_DAO::singleValueQuery($sql);
      if (!isset($trxn_id)) {
        // If we don't have a matching recurring contribution in CiviCRM, unset and don't try to sync. Run Reconciliation to create a recur in Civi
        unset($smartDebitPayerContacts[$key]);
      }
    }
    if (empty($smartDebitPayerContacts))
      return FALSE;

    // Clear out the results table
    $emptySql = "TRUNCATE TABLE veda_smartdebit_success_contributions";
    CRM_Core_DAO::executeQuery($emptySql);

    // Set the Number of Rounds
    $count = count($smartDebitPayerContacts);
    $rounds = ceil($count/self::BATCH_COUNT);
    // Setup a Task in the Queue
    $i = 0;
    while ($i < $rounds) {
      $start   = $i * self::BATCH_COUNT;
      $smartDebitPayerContactsBatch  = array_slice($smartDebitPayerContacts, $start, self::BATCH_COUNT, TRUE);
      $counter = ($rounds > 1) ? ($start + self::BATCH_COUNT) : $count;
      if ($counter > $count) $counter = $count;
      $task    = new CRM_Queue_Task(
        array('CRM_Smartdebit_Sync', 'syncSmartdebitCollectionReports'),
        array($smartDebitPayerContactsBatch),
        "Syncing smart debit collection reports - contacts {$counter} of {$count}"
      );

      // Add the Task to the Queue
      $queue->createItem($task);
      $i++;
    }

    Civi::log()->debug('Smartdebit Sync: Retrieving AUDDIS reports.');
    // Get auddis/arudd IDs for last month if none specified.
    $auddisProcessor = new CRM_Smartdebit_Auddis();

    if (!isset($auddisIDs)) {
      // Get list of auddis records from smart debit
      if ($auddisProcessor->getSmartdebitAuddisList()) {
        // Get list of auddis dates, convert them to IDs
        if ($auddisProcessor->getAuddisDates()) {
          $auddisIDs = $auddisProcessor->getAuddisIdsForProcessing($auddisProcessor->getAuddisDatesList());
        }
      }
    }
    if (!empty($auddisIDs)) {
      $task = new CRM_Queue_Task(
        array('CRM_Smartdebit_Sync', 'syncSmartdebitAuddis'),
        array($auddisIDs),
        "Syncing smart debit AUDDIS reports"
      );
      $queue->createItem($task);
    }

    Civi::log()->debug('Smartdebit Sync: Retrieving ARUDD reports.');
    if (!isset($aruddIDs)) {
      // Get list of auddis records from smart debit
      if ($auddisProcessor->getSmartdebitAruddList()) {
        // Get list of auddis dates, convert them to IDs
        if ($auddisProcessor->getAruddDates()) {
          $aruddIDs = $auddisProcessor->getAruddIDsForProcessing($auddisProcessor->getAruddDatesList());
        }
      }
    }
    if (!empty($aruddIDs)) {
      $task = new CRM_Queue_Task(
        array('CRM_Smartdebit_Sync', 'syncSmartdebitArudd'),
        array($aruddIDs),
        "Syncing smart debit ARUDD reports"
      );
      $queue->createItem($task);
    }

    $task = new CRM_Queue_Task(
      array('CRM_Smartdebit_Auddis', 'removeOldSmartdebitCollectionReports'),
      array(),
      'Clean up old collection reports'
    );
    $queue->createItem($task);

    // Update recurring contributions
    $task = new CRM_Queue_Task(
      array('CRM_Smartdebit_Sync', 'updateRecurringContributionsTask'),
      array($smartDebitPayerContacts),
      'Update Recurring Contributions in CiviCRM'
    );
    $queue->createItem($task);

    // Setup the Runner
    $runnerParams = array(
      'title' => ts('Import From Smart Debit'),
      'queue' => $queue,
      'errorMode'=> CRM_Queue_Runner::ERROR_ABORT,
    );
    if ($interactive) {
      $runnerParams['onEndUrl'] = CRM_Utils_System::url(self::END_URL, self::END_PARAMS, TRUE, NULL, FALSE);
    }
    $runner = new CRM_Queue_Runner($runnerParams);

    return $runner;
  }

  public static function runViaWeb($runner) {
    if ($runner) {
      // Run Everything in the Queue via the Web.
      $runner->runAllViaWeb();
    }
    else {
      CRM_Core_Session::setStatus(ts('No records were synchronised.'));
      $url = CRM_Utils_System::url(CRM_Smartdebit_Sync::END_URL, CRM_Smartdebit_Sync::END_PARAMS, TRUE, NULL, FALSE);
      CRM_Utils_System::redirect($url);
    }
  }

  /**
   * Batch task to retrieve daily collection reports
   */
  public static function retrieveDailyCollectionReport() {
    // Get collection report for today
    Civi::log()->debug('Smartdebit cron: Retrieving Daily Collection Report.');
    $date = new DateTime();
    $collections = CRM_Smartdebit_Api::getCollectionReport($date->format('Y-m-d'));
    if (!isset($collections['error'])) {
      CRM_Smartdebit_Auddis::saveSmartdebitCollectionReport($collections);
    }
  }

  /**
   * Batch task to retrieve payer contact details (mandates)
   */
  public static function retrievePayerContactDetails() {
    Civi::log()->debug('Smartdebit Sync: Retrieving Smart Debit Payer Contact Details.');
    // Get list of payers from Smartdebit
    $smartDebitPayerContacts = CRM_Smartdebit_Api::getPayerContactDetails();

    // Update mandates table for reconciliation functions
    self::updateSmartDebitMandatesTable($smartDebitPayerContacts, TRUE);
    return $smartDebitPayerContacts;
  }

/**
   * Sync the AUDDIS records with contacts
   *
   * @param CRM_Queue_TaskContext $ctx
   * @param $smartDebitAuddisIds
   */
  public static function syncSmartdebitAuddis(CRM_Queue_TaskContext $ctx, $smartDebitAuddisIds)
  {
    // Add contributions for rejected payments with the status of 'failed'
    // Reset the counter when sync starts
    CRM_Smartdebit_Settings::save(array('rejected_auddis' => NULL));
    // Rejected Ids is used to display on confirm form, would be nice to tidy and have it's own table or something
    $rejectedIds = array();

    // Retrieve AUDDIS files from Smartdebit
    if ($smartDebitAuddisIds) {
      // Find the relevant auddis file
      foreach ($smartDebitAuddisIds as $auddisId) {
        // Process AUDDIS files
        $auddisFile = CRM_Smartdebit_Api::getAuddisFile($auddisId);
        unset($auddisFile['auddis_date']);
        $refKey = 'reference';
        $dateKey = 'effective-date';
        $rejectedIds = array_merge($rejectedIds, CRM_Smartdebit_Sync::processAuddisFile($auddisId, $auddisFile, $refKey, $dateKey, 'SDAUDDIS'));
      }
    }
    CRM_Smartdebit_Settings::save(array('rejected_auddis' => $rejectedIds));
    return CRM_Queue_Task::TASK_SUCCESS;
  }

  /**
   * Sync the ARUDD records with contacts
   *
   * @param CRM_Queue_TaskContext $ctx
   * @param $smartDebitAruddIds
   *
   * @return int
   */
  public static function syncSmartdebitArudd (CRM_Queue_TaskContext $ctx, $smartDebitAruddIds) {
    // Add contributions for rejected payments with the status of 'failed'
    $rejectedIds = array();
    // Reset the counter when sync starts
    CRM_Smartdebit_Settings::save(array('rejected_arudd' => NULL));

    // Retrieve ARUDD files from Smartdebit
    if($smartDebitAruddIds) {
      foreach ($smartDebitAruddIds as $aruddId) {
        // Process ARUDD files
        $aruddFile = CRM_Smartdebit_Api::getAruddFile($aruddId);
        unset($aruddFile['arudd_date']);
        $refKey = 'ref';
        $dateKey = 'originalProcessingDate';
        $rejectedIds = array_merge($rejectedIds, CRM_Smartdebit_Sync::processAuddisFile($aruddId, $aruddFile, $refKey, $dateKey, 'SDARUDD'));
      }
    }
    CRM_Smartdebit_Settings::save(array('rejected_arudd' => $rejectedIds));

    Civi::log()->debug('Smartdebit: Sync Job End.');
    return CRM_Queue_Task::TASK_SUCCESS;
  }

  /**
   * Synchronise smart debit payments with CiviCRM
   * We only create new contributions here, anything else has to be done manually using reconciliation
   *
   * @param CRM_Queue_TaskContext $ctx
   * @param $smartDebitPayerContacts
   * @return int
   */
  public static function syncSmartdebitCollectionReports(CRM_Queue_TaskContext $ctx, $smartDebitPayerContacts)
  {
    // Import each transaction from smart debit
    foreach ($smartDebitPayerContacts as $key => $sdContact) {
      // Get transaction details from collection report
      $selectQuery = "SELECT `receive_date` as receive_date, `amount` as amount 
                      FROM `veda_smartdebit_collectionreports` 
                      WHERE `transaction_id` = %1";
      $params = array(1 => array($sdContact['reference_number'], 'String'));
      $daoCollectionReport = CRM_Core_DAO::executeQuery($selectQuery, $params);
      if (!$daoCollectionReport->fetch()) {
        Civi::log()->debug('Smartdebit syncSmartdebitRecords: No collection report for ' . $sdContact['reference_number']);
        continue;
      }

      self::processCollection($sdContact['reference_number'], $daoCollectionReport->receive_date, TRUE, $daoCollectionReport->amount, 'SDCR');
    }
    return CRM_Queue_Task::TASK_SUCCESS;
  }

  /**
   * Process the collection/auddis/arudd record and add/update contributions as required
   * @param string $trxnId
   * @param string $receiveDate
   * @param bool $contributionSuccess
   * @param float $amount
   * @param string $collectionDescription
   *
   * @return integer|bool
   */
  private static function processCollection($trxnId, $receiveDate, $contributionSuccess, $amount, $collectionDescription) {
    if (empty($trxnId) || empty($receiveDate)) {
      // amount can be empty
      return FALSE;
    }

    // Get existing recurring contribution
    try {
      $contributionRecur = civicrm_api3('ContributionRecur', 'getsingle', array(
        'trxn_id' => $trxnId,
      ));
    } catch (Exception $e) {
      Civi::log()->debug('Smartdebit processCollection: Not Matched=' . $trxnId);
      return FALSE;
    }
    if (CRM_Smartdebit_Settings::getValue('debug')) { Civi::log()->debug('Smartdebit processCollection: $contributionRecur=' . print_r($contributionRecur, true)); }
    if (CRM_Smartdebit_Settings::getValue('debug')) { Civi::log()->debug('Smartdebit processCollection: Matched=' . $trxnId); }

    if (empty($amount)) {
      $amount = $contributionRecur['amount'];
    }
    // Smart debit charge file has dates in UK format
    // UK dates (eg. 27/05/1990) won't work with strtotime, even with timezone properly set.
    // However, if you just replace "/" with "-" it will work fine.
    $receiveDate = date('Y-m-d', strtotime(str_replace('/', '-', $receiveDate)));

    $contributeParams =
      array(
        'contact_id' => $contributionRecur['contact_id'],
        'contribution_recur_id' => $contributionRecur['id'],
        'total_amount' => $amount,
        'invoice_id' => md5(uniqid(rand(), TRUE)),
        'trxn_id' => $trxnId . '/' . CRM_Utils_Date::processDate($receiveDate),
        'financial_type_id' => $contributionRecur['financial_type_id'],
        'payment_instrument_id' => $contributionRecur['payment_instrument_id'],
        'receive_date' => CRM_Utils_Date::processDate($receiveDate),
      );

    // Check if the contribution is first payment
    // if yes, update the contribution instead of creating one
    // as CiviCRM should have created the first contribution
    list($firstPayment, $contributeParams) = self::checkIfFirstPayment($contributeParams, $contributionRecur);

    $contributeParams['source'] = $collectionDescription;
    try {
      // Try to get description for contribution from membership
      $membership = civicrm_api3('Membership', 'getsingle', array(
        'contribution_recur_id' => $contributionRecur['id'],
      ));
      if (!empty($membership['source'])) {
        $contributeParams['source'] = $collectionDescription . ' - ' . $membership['source'];
      }
    }
    catch (Exception $e) {
      // Do nothing, we just use passed in description
    }

    // Allow params to be modified via hook
    CRM_Smartdebit_Hook::alterContributionParams($contributeParams, $firstPayment);

    if (CRM_Smartdebit_Settings::getValue('debug')) { Civi::log()->debug('Smartdebit processCollection: $contribution=' . print_r($contributeParams, true)); }

    if ($contributionSuccess) {
      if ($firstPayment) {
        if (CRM_Smartdebit_Settings::getValue('debug')) { Civi::log()->debug('Smartdebit processCollection: success firstpayment (recur:' . $contributionRecur['id'] . ')'); }
        // Update contribution that was created when we setup the recurring/contribution.
        $contributeResult = CRM_Smartdebit_Base::createContribution($contributeParams);
      }
      else {
        if (CRM_Smartdebit_Settings::getValue('debug')) { Civi::log()->debug('Smartdebit processCollection: success recurpayment (recur:' . $contributionRecur['id'] . ')'); }
        // If payment is successful, we call repeattransaction to create a new contribution and update/renew related memberships/events.
        civicrm_api3('contribution', 'repeattransaction', $contributeParams);
      }
    }
    else {
      // If payment failed, we create the contribution as failed, and don't call completetransaction (as we don't want to update/renew related memberships/events).
      $contributeParams['contribution_status_id'] = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Failed');
      if ($firstPayment) {
        if (CRM_Smartdebit_Settings::getValue('debug')) { Civi::log()->debug('Smartdebit processCollection: failed firstpayment (recur:' . $contributionRecur['id'] . ')'); }
        $contributeResult = CRM_Smartdebit_Base::createContribution($contributeParams);
      }
      else {
        if (CRM_Smartdebit_Settings::getValue('debug')) { Civi::log()->debug('Smartdebit processCollection: failed recurpayment (recur:' . $contributionRecur['id'] . ')'); }
        civicrm_api3('contribution', 'repeattransaction', $contributeParams);
      }
    }

    if (CRM_Smartdebit_Settings::getValue('debug')) { Civi::log()->debug('Smartdebit processCollection: $contributeParams=' . print_r($contributeParams, true)); }
    if (CRM_Smartdebit_Settings::getValue('debug')) { Civi::log()->debug('Smartdebit processCollection: $contributeResult=' . print_r($contributeResult, true)); }

    if (empty($contributeResult['is_error'])) {
      // Get recurring contribution ID
      // get contact display name to display in result screen
      $contactResult = civicrm_api3('Contact', 'getsingle', array('id' => $contributionRecur['contact_id']));

      // Update Recurring contribution to "In Progress"
      $contributionRecur['contribution_status_id'] = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'In Progress');
      if (CRM_Smartdebit_Settings::getValue('debug')) { Civi::log()->debug('Smartdebit processCollection: Updating contributionrecur=' . $contributionRecur['id']); }
      CRM_Smartdebit_Base::createRecurContribution($contributionRecur);

      // Only record completed collections in DB
      if ($contributionSuccess) {
        // Store the results in veda_smartdebit_success_contributions table
        $keepSuccessResultsSQL = "
          INSERT INTO `veda_smartdebit_success_contributions`(
            `transaction_id`,
            `contribution_id`,
            `contact_id`,
            `contact`,
            `amount`,
            `frequency`
            )
          VALUES (%1,%2,%3,%4,%5,%6)
        ";
        $keepSuccessResultsParams = array(
          1 => array($trxnId, 'String'),
          2 => array($contributeResult['id'], 'Integer'),
          3 => array($contactResult['id'], 'Integer'),
          4 => array($contactResult['display_name'], 'String'),
          5 => array($amount, 'String'),
          6 => array($contributionRecur['frequency_interval'] . ' ' . $contributionRecur['frequency_unit'], 'String'),
        );
        CRM_Core_DAO::executeQuery($keepSuccessResultsSQL, $keepSuccessResultsParams);
      }
      return $contributeResult['id'];
    }
    return FALSE;
  }

  /**
   * This function is used to process Auddis and Arudd records from an Auddis/Arudd file
   *
   * @param $auddisId
   * @param $auddisFile
   * @param $refKey
   * @param $dateKey
   * @return array|bool
   */
  private static function processAuddisFile($auddisId, $auddisFile, $refKey, $dateKey, $collectionDescription) {
    $errors = FALSE;
    $rejectedIds = array();

    // Process each record in the auddis file
    foreach ($auddisFile as $key => $value) {
      if (!isset($value[$refKey]) || !isset($value[$dateKey])) {
        Civi::log()->debug('Smartdebit processAuddis. Id=' . $auddisId . '. Malformed Auddis/Arudd record from Smartdebit.');
        continue;
      }

      $contributionId = self::processCollection($value[$refKey], $value[$dateKey], FALSE, 0, $collectionDescription);

      if ($contributionId) {
        // Look for an existing contribution
        try {
          $existingContribution = civicrm_api3('Contribution', 'getsingle', array(
            'return' => array("id"),
            'id' => $contributionId,
          ));
        } catch (Exception $e) {
          return FALSE;
        }

        // get contact display name to display in result screen
        $contactParams = array('version' => 3, 'id' => $existingContribution['contact_id']);
        $contactResult = civicrm_api('Contact', 'getsingle', $contactParams);

        $rejectedIds[$contributionId] = array('cid' => $existingContribution['contact_id'],
          'id' => $contributionId,
          'display_name' => $contactResult['display_name'],
          'total_amount' => CRM_Utils_Money::format($existingContribution['total_amount']),
          'trxn_id' => $value[$refKey],
          'status' => $existingContribution['label'],
        );

        // Allow auddis rejected contribution to be handled by hook
        CRM_Smartdebit_Hook::handleAuddisRejectedContribution($contributionId);
      } else {
        Civi::log()->debug('Smartdebit processAuddis: ' . $value[$refKey] . ' NOT matched to contribution in CiviCRM - try reconciliation.');
        $errors = TRUE;
      }
    }
    if (!$errors) {
      // Mark auddis as processed if we actually found a matching contribution
      CRM_Smartdebit_Auddis::setAuddisRecordProcessed($auddisId);
    }

    return $rejectedIds;
  }

  /**
   * Function to check if the contribution is first contribution
   * for the recurring contribution record
   *
   * @param $newContribution
   * @param $contributionRecur
   *
   * @return array (bool: First Contribution, array: contributionrecord)
   */
  private static function checkIfFirstPayment($newContribution, $contributionRecur) {
    if (empty($newContribution['contribution_recur_id'])) {
      if (CRM_Smartdebit_Settings::getValue('debug')) { Civi::log()->debug('Smartdebit checkIfFirstPayment: No recur_id'); }
      return array(FALSE, NULL);
    }
    if (empty($contributionRecur['frequency_unit'])) {
      $contributionRecur['frequency_unit'] = 'year';
    }
    if (empty($contributionRecur['frequency_interval'])) {
      $contributionRecur['frequency_interval'] = 1;
    }

    $contributionResult = civicrm_api3('Contribution', 'get', array(
      'sequential' => 1,
      'options' => array('sort' => "receive_date DESC"),
      'contribution_recur_id' => $newContribution['contribution_recur_id'],
    ));

    // We have only one contribution for the recurring record
    if ($contributionResult['count'] > 0) {
      if (CRM_Smartdebit_Settings::getValue('debug')) { Civi::log()->debug('Smartdebit checkIfFirstPayment: '.$contributionResult['count'].' contribution(s). id='.$contributionResult['id']); }

      foreach ($contributionResult['values'] as $contributionDetails) {
        // Check if trxn_ids are identical, if so, update this trxn
        if (strcmp($contributionDetails['trxn_id'], $newContribution['trxn_id']) == 0) {
          $newContribution['id'] = $contributionDetails['id'];
          if (CRM_Smartdebit_Settings::getValue('debug')) {
            Civi::log()->debug('Smartdebit checkIfFirstPayment: Identical-Using existing contribution');
          }
          return array(TRUE, $newContribution);
        }
      }

      $contributionDetails = $contributionResult['values'][0];
      // Check if the transaction Id is one of ours, and not identical
      if (!empty($contributionDetails['trxn_id'])) {
        // Does our trxn_id start with the recurring one?
        if (strcmp(substr($contributionDetails['trxn_id'], 0, strlen($contributionRecur['trxn_id'])), $contributionRecur['trxn_id']) == 0) {
          // Does our trxn_id contain a '/' after the ref?
          if (strcmp(substr($contributionDetails['trxn_id'], strlen($contributionRecur['trxn_id']), 1), '/') == 0) {
            // Not identical but one of ours, so we'll create a new one
            if (CRM_Smartdebit_Settings::getValue('debug')) { Civi::log()->debug('Smartdebit checkIfFirstPayment: Not identical,ours. Creating new contribution'); }
            return array(FALSE, $newContribution);
          }
        }
      }

      if (!empty($contributionDetails['receive_date']) && !empty($newContribution['receive_date'])) {
        // Find the date difference between the contribution date and new collection date
        $dateDiff = CRM_Smartdebit_Sync::dateDifference($newContribution['receive_date'], $contributionDetails['receive_date']);
        // Get days difference to determine if this is first payment
        $days = CRM_Smartdebit_Sync::daysDifferenceForFrequency($contributionRecur['frequency_unit'], $contributionRecur['frequency_interval']);

        // if diff is less than set number of days, return Contribution ID to update the contribution
        // If $days == 0 it's a lifetime membership
        if (($dateDiff < $days) && ($days != 0)) {
          if (CRM_Smartdebit_Settings::getValue('debug')) { Civi::log()->debug('Smartdebit checkIfFirstPayment: Within dates,Using existing contribution'); }
          $newContribution['id'] = $contributionDetails['id'];
          return array(TRUE, $newContribution);
        }
      }
    }
    // If no contributions linked to recur, it must be the first contribution!
    return array(TRUE, $newContribution);
  }

  /**
   * Return difference between two dates in format
   * @param $date_1
   * @param $date_2
   * @param string $differenceFormat
   * @return string
   */
  private static function dateDifference($date_1, $date_2, $differenceFormat = '%a')
  {
    $datetime1 = date_create($date_1);
    $datetime2 = date_create($date_2);

    $interval = date_diff($datetime1, $datetime2);

    return $interval->format($differenceFormat);

  }

  /**
   * Function to return number of days difference to check between current date
   * and payment date to determine if this is first payment or not
   *
   * @param $frequencyUnit
   * @param $frequencyInterval
   * @return int
   */
  private static function daysDifferenceForFrequency($frequencyUnit, $frequencyInterval) {
    switch ($frequencyUnit) {
      case 'day':
        $days = $frequencyInterval * 1;
        break;
      case 'month':
        $days = $frequencyInterval * 7;
        break;
      case 'year':
        $days = $frequencyInterval * 30;
        break;
      case 'lifetime':
        $days = 0;
        break;
      default:
        $days = 30;
        break;
    }
    return $days;
  }

  /**
   * Update Smartdebit Mandates in table veda_smartdebit_mandates for further analysis
   * This table is only used by Reconciliation functions
   *
   * @param array $smartDebitPayerContactDetails (array of smart debit contact details : call CRM_Smartdebit_Api::getPayerContactDetails())
   * @param bool $truncate If true, truncate the table before inserting new records.
   * @return bool|int
   */
  public static function updateSmartDebitMandatesTable($smartDebitPayerContactDetails, $truncate = FALSE) {
    if ($truncate) {
      // if the civicrm_sd table exists, then empty it
      $emptySql = "TRUNCATE TABLE `veda_smartdebit_mandates`";
      CRM_Core_DAO::executeQuery($emptySql);
    }

    // Get payer contact details
    if (empty($smartDebitPayerContactDetails)) {
      return FALSE;
    }
    // Insert mandates into table
    foreach ($smartDebitPayerContactDetails as $key => $smartDebitRecord) {
      if (!$truncate) {
        $deleteSql = "DELETE FROM `veda_smartdebit_mandates` WHERE reference_number='%1'";
        $deleteParams = array(1 => $smartDebitRecord['reference_number']);
        CRM_Core_DAO::executeQuery($deleteSql);
      }

      $sql = "INSERT INTO `veda_smartdebit_mandates`(
            `title`,
            `first_name`,
            `last_name`, 
            `email_address`,
            `address_1`, 
            `address_2`, 
            `address_3`, 
            `town`, 
            `county`,
            `postcode`,
            `first_amount`,
            `regular_amount`,
            `frequency_type`,
            `frequency_factor`,
            `start_date`,
            `current_state`,
            `reference_number`,
            `payerReference`
            ) 
            VALUES (%1,%2,%3,%4,%5,%6,%7,%8,%9,%10,%11,%12,%13,%14,%15,%16,%17,%18)";
      $params = array(
        1 => array( CRM_Smartdebit_Utils::getArrayFieldValue($smartDebitRecord, 'title', 'NULL'), 'String' ),
        2 => array( CRM_Smartdebit_Utils::getArrayFieldValue($smartDebitRecord, 'first_name', 'NULL'), 'String' ),
        3 => array( CRM_Smartdebit_Utils::getArrayFieldValue($smartDebitRecord, 'last_name', 'NULL'), 'String' ),
        4 => array( CRM_Smartdebit_Utils::getArrayFieldValue($smartDebitRecord, 'email_address', 'NULL'),  'String'),
        5 => array( CRM_Smartdebit_Utils::getArrayFieldValue($smartDebitRecord, 'address_1', 'NULL'), 'String' ),
        6 => array( CRM_Smartdebit_Utils::getArrayFieldValue($smartDebitRecord, 'address_2', 'NULL'), 'String' ),
        7 => array( CRM_Smartdebit_Utils::getArrayFieldValue($smartDebitRecord, 'address_3', 'NULL'), 'String' ),
        8 => array( CRM_Smartdebit_Utils::getArrayFieldValue($smartDebitRecord, 'town', 'NULL'), 'String' ),
        9 => array( CRM_Smartdebit_Utils::getArrayFieldValue($smartDebitRecord, 'county', 'NULL'), 'String' ),
        10 => array( CRM_Smartdebit_Utils::getArrayFieldValue($smartDebitRecord, 'postcode', 'NULL'), 'String' ),
        11 => array( CRM_Smartdebit_Utils::getCleanSmartdebitAmount(CRM_Smartdebit_Utils::getArrayFieldValue($smartDebitRecord, 'first_amount', 'NULL')), 'String' ),
        12 => array( CRM_Smartdebit_Utils::getCleanSmartdebitAmount(CRM_Smartdebit_Utils::getArrayFieldValue($smartDebitRecord, 'regular_amount', 'NULL')), 'String' ),
        13 => array( CRM_Smartdebit_Utils::getArrayFieldValue($smartDebitRecord, 'frequency_type', 'NULL'), 'String' ),
        14 => array( CRM_Smartdebit_Utils::getArrayFieldValue($smartDebitRecord, 'frequency_factor', 'NULL'), 'Int' ),
        15 => array( CRM_Smartdebit_Utils::getArrayFieldValue($smartDebitRecord, 'start_date', 'NULL'), 'String' ),
        16 => array( CRM_Smartdebit_Utils::getArrayFieldValue($smartDebitRecord, 'current_state', 'NULL'), 'Int' ),
        17 => array( CRM_Smartdebit_Utils::getArrayFieldValue($smartDebitRecord, 'reference_number', 'NULL'), 'String' ),
        18 => array( CRM_Smartdebit_Utils::getArrayFieldValue($smartDebitRecord, 'payerReference', 'NULL'), 'String' ),
      );
      CRM_Core_DAO::executeQuery($sql, $params);
    }
    $mandateFetchedCount = count($smartDebitPayerContactDetails);
    return $mandateFetchedCount;
  }

  /**
   * Helper function to trigger updateRecurringContributions via taskrunner
   * @param \CRM_Queue_TaskContext $ctx
   * @param $smartDebitPayerContactDetails
   */
  public static function updateRecurringContributionsTask(CRM_Queue_TaskContext $ctx, $smartDebitPayerContactDetails) {
    self::updateRecurringContributions($smartDebitPayerContactDetails);
  }

  /**
   * Update parameters of CiviCRM recurring contributions that represent Smartdebit Direct Debit Mandates
   *
   * @param $smartDebitPayerContactDetails
   *
   * @throws \CiviCRM_API3_Exception
   */
  public static function updateRecurringContributions($smartDebitPayerContactDetails) {
    foreach ($smartDebitPayerContactDetails as $key => $smartDebitRecord) {
      // Get recur
      try {
        $recurContribution = civicrm_api3('ContributionRecur', 'getsingle', array(
          'trxn_id' => $smartDebitRecord['reference_number'],
        ));
      }
      catch (CiviCRM_API3_Exception $e) {
        // Recurring contribution with transaction ID does not exist
        continue;
      }

      $recurContributionOriginal = $recurContribution;
      // Update the recurring contribution
      $recurContribution['amount'] = filter_var($smartDebitRecord['regular_amount'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
      list($recurContribution['frequency_unit'], $recurContribution['frequency_interval']) =
        CRM_Smartdebit_Base::translateSmartdebitFrequencytoCiviCRM($smartDebitRecord['frequency_type'], $smartDebitRecord['frequency_factor']);

      switch ($smartDebitRecord['current_state']) {
        case CRM_Smartdebit_Api::SD_STATE_LIVE:
        case CRM_Smartdebit_Api::SD_STATE_NEW:
          // Clear cancel date and set status if live
          $recurContribution['cancel_date'] = '';
          if (($recurContribution['contribution_status_id'] != CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Pending'))
            && ($recurContribution['contribution_status_id'] != CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'In Progress'))) {
            $recurContribution['contribution_status_id'] = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'In Progress');
          }
          break;
        case CRM_Smartdebit_Api::SD_STATE_CANCELLED:
          $recurContribution['contribution_status_id'] = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Cancelled');
          break;
        case CRM_Smartdebit_Api::SD_STATE_REJECTED:
          $recurContribution['contribution_status_id'] = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Failed');
          break;
      }
      // Hook to allow modifying recurring contribution during sync task
      CRM_Smartdebit_Hook::updateRecurringContribution($recurContribution);
      if ($recurContribution != $recurContributionOriginal) {
        $recurContribution['modified_date'] = (new DateTime())->format('Y-m-d H:i:s');
        civicrm_api3('ContributionRecur', 'create', $recurContribution);
      }
    }
  }

}
