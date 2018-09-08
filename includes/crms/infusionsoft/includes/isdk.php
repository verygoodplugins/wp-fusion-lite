<?php
/**
 * @method Object Oriented PHP SDK for Infusionsoft
 * @CreatedBy Justin Morris on 09-10-08
 * @UpdatedBy Michael Fairchild
 * @Updated 01/14/2014
 * @iSDKVersion 1.8.6
 * @ApplicationVersion 1.29.9
 */

if ( ! class_exists( 'xmlrpc_client' ) ) {
	include( "xmlrpc-4.0/lib/xmlrpc.inc" );
}

if ( ! class_exists( 'WPF_iSDKException' ) ) {
	class WPF_iSDKException extends Exception {
	}
}

if ( ! class_exists( 'WPF_iSDK' ) ) {
	class WPF_iSDK {

		static private $handle;
		public $logname = '';
		public $loggingEnabled = 0;

		/**
		 * @method cfgCon
		 * @description Creates and tests the API Connection to the Application
		 *
		 * @param $name - Application Name
		 * @param string $key - API Key
		 * @param string $dbOn - Error Handling On
		 * @param string $type - Infusionsoft or Mortgage Pro
		 *
		 * @return bool
		 * @throws WPF_iSDKException
		 */
		public function cfgCon( $name, $key = "", $dbOn = "on" ) {
			$this->debug = ( ( $key == 'on' || $key == 'off' || $key == 'kill' || $key == 'throw' ) ? $key : $dbOn );

			if ( $key != "" && $key != "on" && $key != "off" && $key != 'kill' && $key != 'throw' ) {
				$this->key = $key;
			} else {
				include( 'conn.cfg.php' );
				$appLines = $connInfo;
				foreach ( $appLines as $appLine ) {
					$details[ substr( $appLine, 0, strpos( $appLine, ":" ) ) ] = explode( ":", $appLine );
				}
				$appname   = $details[ $name ][1];
				$this->key = $details[ $name ][3];
			}

			if ( ! isset( $appname ) ) {
				$appname = $name;
			}

			$this->client = new xmlrpc_client( "https://$appname.infusionsoft.com/api/xmlrpc" );

			/* Return Raw PHP Types */
			$this->client->return_type = "phpvals";

			/* SSL Certificate Verification */
			$this->client->setSSLVerifyPeer( true );
			$this->client->setCaCertificate( ( __DIR__ != '__DIR__' ? __DIR__ : dirname( __FILE__ ) ) . '/infusionsoft.pem' );
			//$this->client->setDebug(2);

			$this->encKey = php_xmlrpc_encode( $this->key );

			/* Connection verification */
			$connected = $this->dsGetSetting( "Application", "enabled" );

			if( is_wp_error( $connected ) ) {
				return $connected;
			}

			return true;
		}

		/**
		 * @method getTemporaryKey
		 * @description Connect and Obtain an API key from a vendor key
		 *
		 * @param string $name - Application Name
		 * @param string $user - Username
		 * @param string $pass - Password
		 * @param string $key - Vendor Key
		 * @param string $dbOn - Error Handling On
		 *
		 * @return bool
		 * @throws WPF_iSDKException
		 */
		public function vendorCon( $name, $user, $pass, $key = "", $dbOn = "on" ) {
			$this->debug = ( ( $key == 'on' || $key == 'off' || $key == 'kill' || $key == 'throw' ) ? $key : $dbOn );

			if ( $key != "" && $key != "on" && $key != "off" && $key != 'kill' && $key != 'throw' ) {
				$this->client = new xmlrpc_client( "https://$name.infusionsoft.com/api/xmlrpc" );
				$this->key    = $key;
			} else {
				include( 'conn.cfg.php' );
				$appLines = $connInfo;
				foreach ( $appLines as $appLine ) {
					$details[ substr( $appLine, 0, strpos( $appLine, ":" ) ) ] = explode( ":", $appLine );
				}
				if ( ! empty( $details[ $name ] ) ) {
					if ( $details[ $name ][2] == "i" ) {
						$this->client = new xmlrpc_client( "https://" . $details[ $name ][1] .
						                                   ".infusionsoft.com/api/xmlrpc" );
					} elseif ( $details[ $name ][2] == "m" ) {
						$this->client = new xmlrpc_client( "https://" . $details[ $name ][1] .
						                                   ".mortgageprocrm.com/api/xmlrpc" );
					} else {
						throw new WPF_iSDKException( "Invalid application name: \"" . $name . "\"" );
					}
				} else {
					throw new WPF_iSDKException( "Application Does Not Exist: \"" . $name . "\"" );
				}
				$this->key = $details[ $name ][3];
			}

			/* Return Raw PHP Types */
			$this->client->return_type = "phpvals";

			/* SSL Certificate Verification */
			$this->client->setSSLVerifyPeer( true );
			$this->client->setCaCertificate( ( __DIR__ != '__DIR__' ? __DIR__ : dirname( __FILE__ ) ) . '/infusionsoft.pem' );

			$carray = array(
				php_xmlrpc_encode( $this->key ),
				php_xmlrpc_encode( $user ),
				php_xmlrpc_encode( md5( $pass ) )
			);

			$this->key = $this->methodCaller( "DataService.getTemporaryKey", $carray );

			$this->encKey = php_xmlrpc_encode( $this->key );

			try {
				$connected = $this->dsGetSetting( "Application", "enabled" );
			} catch( WPF_iSDKException $e ) {
				throw new WPF_iSDKException( "Connection Failed" );
			}

			return true;
		}

		/**
		 * @method echo
		 * @description Worthless public function, used to validate a connection
		 *
		 * @param string $txt
		 *
		 * @return int|mixed|string
		 */
		public function appEcho( $txt ) {
			$carray = array(
				php_xmlrpc_encode( $txt )
			);

			return $this->methodCaller( "DataService.echo", $carray );
		}

		// WPF formatting for HTML-ENTITIES
		public function wpf_convert_encoding( &$item ) {
			if(function_exists('mb_convert_encoding')) {
				$item = mb_convert_encoding( $item, 'HTML-ENTITIES', 'UTF-8' );
			}
		}

		/**
		 * @method Method Caller
		 * @description Builds XML and Sends the Call
		 *
		 * @param string $service
		 * @param array $callArray
		 *
		 * @return int|mixed|string
		 * @throws WPF_iSDKException
		 */
		public function methodCaller( $service, $callArray ) {
			/* Set up the call */
			$call = new xmlrpcmsg( $service, $callArray );

			if ( $service != 'DataService.getTemporaryKey' ) {
				array_unshift( $call->params, $this->encKey );
			}

			/* Send the call */
			$now    = time();
			$start  = microtime();
			$result = $this->client->send( $call );

			$stop = microtime();
			/* Check the returned value to see if it was successful and return it */
			if ( ! $result->faultCode() ) {
				if ( $this->loggingEnabled == 1 ) {
					$this->log( array(
						'Method'    => $service,
						'Call'      => $callArray,
						'Start'     => $start,
						'Stop'      => $stop,
						'Now'       => $now,
						'Result'    => $result,
						'Error'     => 'No',
						'ErrorCode' => 'No Error Code Received'
					) );
				}

				// WPF formatting for HTML-ENTITIES
				$value = $result->value();

				if ( is_array( $value ) ) {
					array_walk_recursive( $value, array( $this, 'wpf_convert_encoding' ) );
				}

				return $value;

			} else {

				if ( $this->loggingEnabled == 1 ) {
					$this->log( array(
						'Method'    => $service,
						'Call'      => $callArray,
						'Start'     => $start,
						'Stop'      => $stop,
						'Now'       => $now,
						'Result'    => $result,
						'Error'     => 'Yes',
						'ErrorCode' => "ERROR: " . $result->faultCode() . " - " . $result->faultString()
					) );
				}
				
				if ( $this->debug == "kill" ) {

					die( "ERROR: " . $result->faultCode() . " - " . $result->faultString() );

				} elseif ( $this->debug == "on" ) {

					return "ERROR: " . $result->faultCode() . " - " . $result->faultString();

				} elseif ( $this->debug == "throw" ) {

					throw new WPF_iSDKException( $result->faultString(), $result->faultCode() );

				} elseif ( $this->debug == "off" ) {

					return new WP_Error( 'error', 'Infusionsoft API call failed with method <strong>' . $service . '</strong>. Message: <strong>' . $result->faultString() . '</strong>' );

				}
			}

		}

		public function listLinkedContacts( $contact_id = 0 ) {
			$contact_id = (int) $contact_id;

			$carray = array(
				php_xmlrpc_encode( $this->key ),
				php_xmlrpc_encode( $contact_id ),
			);

			return $this->methodCaller( 'ContactService.listLinkedContacts', $carray );
		}

		public function LinkContacts( $contact1 = 0, $contact2 = 0, $link_type = 0 ) {
			$contact1  = (int) $contact1;
			$contact2  = (int) $contact2;
			$link_type = (int) $link_type;

			$carray = array(
				php_xmlrpc_encode( $this->key ),
				php_xmlrpc_encode( (int) $contact1 ),
				php_xmlrpc_encode( (int) $contact2 ),
				php_xmlrpc_encode( (int) $link_type ),
			);

			return $this->methodCaller( 'ContactService.linkContacts', $carray );
		}

		public function UnlinkContacts( $contact1 = 0, $contact2 = 0, $link_type = 0 ) {
			$contact1  = (int) $contact1;
			$contact2  = (int) $contact2;
			$link_type = (int) $link_type;

			$carray = array(
				php_xmlrpc_encode( $this->key ),
				php_xmlrpc_encode( (int) $contact1 ),
				php_xmlrpc_encode( (int) $contact2 ),
				php_xmlrpc_encode( (int) $link_type ),
			);

			return $this->methodCaller( 'ContactService.unlinkContacts', $carray );
		}

		/**
		 * @service Affiliate Program Service
		 */

		/**
		 * @method getAffiliatesByProgram
		 * @description Gets a list of all of the affiliates with their contact data for the specified program.  This includes all of the custom fields defined for the contact and affiliate records that are retrieved.
		 *
		 * @param int $programId
		 *
		 * @return array
		 */
		public function getAffiliatesByProgram( $programId ) {
			$carray = array(
				php_xmlrpc_encode( (int) $programId )
			);

			return $this->methodCaller( "AffiliateProgramService.getAffiliatesByProgram", $carray );
		}

		/**
		 * @method getProgramsForAffiliate
		 * @description Gets a list of all of the Affiliate Programs for the Affiliate specified.
		 *
		 * @param int $affiliateId
		 *
		 * @return array
		 */
		public function getProgramsForAffiliate( $affiliateId ) {
			$carray = array(
				php_xmlrpc_encode( (int) $affiliateId )
			);

			return $this->methodCaller( "AffiliateProgramService.getProgramsForAffiliate", $carray );
		}

		/**
		 * @method getAffiliatePrograms
		 * @description Gets a list of all of the Affiliate Programs that are in the application.
		 * @return int|mixed|string
		 */
		public function getAffiliatePrograms() {
			$carray = array();

			return $this->methodCaller( "AffiliateProgramService.getAffiliatePrograms", $carray );
		}

		/**
		 * @method getResourcesForAffiliateProgram
		 * @description Gets a list of all of the resources that are associated to the Affiliate Program specified.
		 *
		 * @param int $programId
		 *
		 * @return array
		 */
		public function getResourcesForAffiliateProgram( $programId ) {
			$carray = array(
				php_xmlrpc_encode( (int) $programId )
			);

			return $this->methodCaller( "AffiliateProgramService.getResourcesForAffiliateProgram", $carray );
		}

		/**
		 * @service Affiliate Service
		 */

		/**
		 * @method affClawbacks
		 * @description returns all clawbacks in a date range
		 *
		 * @param int $affId
		 * @param date $startDate
		 * @param date $endDate
		 *
		 * @return array
		 */
		public function affClawbacks( $affId, $startDate, $endDate ) {
			$carray = array(
				php_xmlrpc_encode( (int) $affId ),
				php_xmlrpc_encode( $startDate, array( 'auto_dates' ) ),
				php_xmlrpc_encode( $endDate, array( 'auto_dates' ) )
			);

			return $this->methodCaller( "APIAffiliateService.affClawbacks", $carray );
		}

		/**
		 * @method affCommissions
		 * @description returns all commissions in a date range
		 *
		 * @param int $affId
		 * @param date $startDate
		 * @param date $endDate
		 *
		 * @return array
		 */
		public function affCommissions( $affId, $startDate, $endDate ) {
			$carray = array(
				php_xmlrpc_encode( (int) $affId ),
				php_xmlrpc_encode( $startDate, array( 'auto_dates' ) ),
				php_xmlrpc_encode( $endDate, array( 'auto_dates' ) )
			);

			return $this->methodCaller( "APIAffiliateService.affCommissions", $carray );
		}

		/**
		 * @method affPayouts
		 * @description returns all affiliate payouts in a date range
		 *
		 * @param int $affId
		 * @param date $startDate
		 * @param date $endDate
		 *
		 * @return array
		 */
		public function affPayouts( $affId, $startDate, $endDate ) {
			$carray = array(
				php_xmlrpc_encode( (int) $affId ),
				php_xmlrpc_encode( $startDate, array( 'auto_dates' ) ),
				php_xmlrpc_encode( $endDate, array( 'auto_dates' ) )
			);

			return $this->methodCaller( "APIAffiliateService.affPayouts", $carray );
		}

		/**
		 * @method affRunningTotals
		 * @description Returns a list with each row representing a single affiliates totals represented by a map with key (one of the names above, and value being the total for that variable)
		 *
		 * @param array $affList
		 *
		 * @return array
		 */
		public function affRunningTotals( $affList ) {
			$carray = array(
				php_xmlrpc_encode( $affList )
			);

			return $this->methodCaller( "APIAffiliateService.affRunningTotals", $carray );
		}

		/**
		 * @method affSummary
		 * @description returns how much the specified affiliates are owed
		 *
		 * @param array $affList
		 * @param date $startDate
		 * @param date $endDate
		 *
		 * @return array
		 */
		public function affSummary( $affList, $startDate, $endDate ) {
			$carray = array(
				php_xmlrpc_encode( $affList ),
				php_xmlrpc_encode( $startDate, array( 'auto_dates' ) ),
				php_xmlrpc_encode( $endDate, array( 'auto_dates' ) )
			);

			return $this->methodCaller( "APIAffiliateService.affSummary", $carray );
		}

		/**
		 * @method getRedirectLinksForAffiliate
		 * @description returns redirect links for affiliate specified
		 *
		 * @param $affiliateId
		 *
		 * @return int|mixed|string
		 */
		public function getRedirectLinksForAffiliate( $affiliateId ) {
			$carray = array(
				php_xmlrpc_encode( (int) $affiliateId )
			);

			return $this->methodCaller( "AffiliateService.getRedirectLinksForAffiliate", $carray );
		}

		/**
		 * @service Contact Service
		 */

		/**
		 * @method add
		 * @description add Contact to Infusionsoft (no duplicate checking)
		 *
		 * @param array $cMap
		 * @param string $optReason
		 *
		 * @return int
		 */
		public function addCon( $cMap, $optReason = "" ) {

			$carray = array(
				php_xmlrpc_encode( $cMap, array( 'auto_dates' ) )
			);

			$conID = $this->methodCaller( "ContactService.add", $carray );
			if ( ! empty( $cMap['Email'] ) ) {
				if ( $optReason == "" ) {
					$this->optIn( $cMap['Email'] );
				} else {
					$this->optIn( $cMap['Email'], $optReason );
				}
			}

			return $conID;
		}

		/**
		 * @method update
		 * @description Update an existing contact
		 *
		 * @param int $cid
		 * @param array $cMap
		 *
		 * @return int
		 */
		public function updateCon( $cid, $cMap ) {

			$carray = array(
				php_xmlrpc_encode( (int) $cid ),
				php_xmlrpc_encode( $cMap, array( 'auto_dates' ) )
			);

			return $this->methodCaller( "ContactService.update", $carray );
		}

		/**
		 * @method merge
		 * @description Merge 2 contacts
		 *
		 * @param int $cid
		 * @param int $dcid
		 *
		 * @return int
		 */
		public function mergeCon( $cid, $dcid ) {
			$carray = array(
				php_xmlrpc_encode( $cid ),
				php_xmlrpc_encode( $dcid )
			);

			return $this->methodCaller( "ContactService.merge", $carray );
		}

		/**
		 * @method findbyEmail
		 * @description finds all contact with an email address
		 *
		 * @param string $eml
		 * @param array $fMap
		 *
		 * @return array
		 */
		public function findByEmail( $eml, $fMap ) {

			$carray = array(
				php_xmlrpc_encode( $eml ),
				php_xmlrpc_encode( $fMap )
			);

			return $this->methodCaller( "ContactService.findByEmail", $carray );
		}

		/**
		 * @method load
		 * @description Loads a contacts data
		 *
		 * @param int $cid
		 * @param array $rFields
		 *
		 * @return array
		 */
		public function loadCon( $cid, $rFields ) {

			$carray = array(
				php_xmlrpc_encode( (int) $cid ),
				php_xmlrpc_encode( $rFields )
			);

			return $this->methodCaller( "ContactService.load", $carray );
		}

		/**
		 * @method addToGroup
		 * @description Apply a Tag to a Contact
		 *
		 * @param int $cid
		 * @param int $gid
		 *
		 * @return bool
		 */
		public function grpAssign( $cid, $gid ) {
			$carray = array(
				php_xmlrpc_encode( (int) $cid ),
				php_xmlrpc_encode( (int) $gid )
			);

			return $this->methodCaller( "ContactService.addToGroup", $carray );
		}

		/**
		 * @method removeFromGroup
		 * @description Remove a Tag from a Contact
		 *
		 * @param int $cid
		 * @param int $gid
		 *
		 * @return bool
		 */
		public function grpRemove( $cid, $gid ) {
			$carray = array(
				php_xmlrpc_encode( (int) $cid ),
				php_xmlrpc_encode( (int) $gid )
			);

			return $this->methodCaller( "ContactService.removeFromGroup", $carray );
		}

		/**
		 * @method resumeCampaignForContact
		 * @description resumes a legacy followup sequence a contact is in
		 *
		 * @param int $cid
		 * @param int $sequenceId
		 *
		 * @return bool
		 */
		public function resumeCampaignForContact( $cid, $sequenceId ) {
			$carray = array(
				php_xmlrpc_encode( (int) $cid ),
				php_xmlrpc_encode( (int) $sequenceId )
			);

			return $this->methodCaller( "ContactService.resumeCampaignForContact", $carray );
		}

		/**
		 * @method addToCampaign
		 * @description adds a contact to a legacy followup sequence
		 *
		 * @param int $cid
		 * @param int $campId
		 *
		 * @return bool
		 */
		public function campAssign( $cid, $campId ) {
			$carray = array(
				php_xmlrpc_encode( (int) $cid ),
				php_xmlrpc_encode( (int) $campId )
			);

			return $this->methodCaller( "ContactService.addToCampaign", $carray );
		}

		/**
		 * @method getNextCampaignStep
		 * @description gets next step in a legacy followup sequence
		 *
		 * @param int $cid
		 * @param int $campId
		 *
		 * @return array
		 */
		public function getNextCampaignStep( $cid, $campId ) {
			$carray = array(
				php_xmlrpc_encode( (int) $cid ),
				php_xmlrpc_encode( (int) $campId )
			);

			return
				$this->methodCaller( "ContactService.getNextCampaignStep", $carray );
		}

		/**
		 * @method getCampaigneeStepDetails
		 * @description get step details for a legacy followup sequence
		 *
		 * @param int $cid
		 * @param int $stepId
		 *
		 * @return array
		 */
		public function getCampaigneeStepDetails( $cid, $stepId ) {
			$carray = array(
				php_xmlrpc_encode( (int) $cid ),
				php_xmlrpc_encode( (int) $stepId )
			);

			return
				$this->methodCaller( "ContactService.getCampaigneeStepDetails", $carray );
		}

		/**
		 * @method rescheduleCampaignStep
		 * @description reschedule a legacy followup sequence
		 *
		 * @param array $cidList
		 * @param int $campId
		 *
		 * @return int
		 */
		public function rescheduleCampaignStep( $cidList, $campId ) {
			$carray = array(
				php_xmlrpc_encode( $cidList ),
				php_xmlrpc_encode( (int) $campId )
			);

			return
				$this->methodCaller( "ContactService.rescheduleCampaignStep", $carray );
		}

		/**
		 * @method removeFromCampaign
		 * @description remove a contact from a legacy followup sequence
		 *
		 * @param int $cid
		 * @param int $campId
		 *
		 * @return bool
		 */
		public function campRemove( $cid, $campId ) {
			$carray = array(
				php_xmlrpc_encode( (int) $cid ),
				php_xmlrpc_encode( (int) $campId )
			);

			return $this->methodCaller( "ContactService.removeFromCampaign", $carray );
		}

		/**
		 * @method pauseCampaign
		 * @description pause a legacy followup sequence for a contact
		 *
		 * @param int $cid
		 * @param int $campId
		 *
		 * @return bool
		 */
		public function campPause( $cid, $campId ) {
			$carray = array(
				php_xmlrpc_encode( (int) $cid ),
				php_xmlrpc_encode( (int) $campId )
			);

			return $this->methodCaller( "ContactService.pauseCampaign", $carray );
		}

		/**
		 * @method runActionSequence
		 * @description run an actionset on a contact
		 *
		 * @param int $cid
		 * @param int $aid
		 *
		 * @return array
		 */
		public function runAS( $cid, $aid ) {
			$carray = array(
				php_xmlrpc_encode( (int) $cid ),
				php_xmlrpc_encode( (int) $aid )
			);

			return $this->methodCaller( "ContactService.runActionSequence", $carray );
		}

		/**
		 * @method applyActivityHistoryTemplate
		 * @description add a note, task, or appointment to a contact from a template
		 *
		 * @param int $contactId
		 * @param int $historyId
		 * @param int $userId
		 *
		 * @return int|mixed|string
		 */
		public function applyActivityHistoryTemplate( $contactId, $historyId, $userId ) {
			$carray = array(
				php_xmlrpc_encode( (int) $contactId ),
				php_xmlrpc_encode( (int) $historyId ),
				php_xmlrpc_encode( (int) $userId )
			);

			return $this->methodCaller( "ContactService.applyActivityHistoryTemplate", $carray );
		}

		/**
		 * @method getActivityHistoryTemplateMap
		 * @description get templates for use with applyActivityHistoryTemplate
		 * @return array
		 */
		public function getActivityHistoryTemplateMap() {
			$carray = array();

			return $this->methodCaller( "ContactService.getActivityHistoryTemplateMap", $carray );
		}

		/**
		 * @method addWithDupCheck
		 * @description add a contact with duplicate checking
		 *
		 * @param array $cMap
		 * @param string $checkType - 'Email', 'EmailAndName', or 'EmailAndNameAnd Company'
		 *
		 * @return int
		 */
		public function addWithDupCheck( $cMap, $checkType ) {
			$carray = array(
				php_xmlrpc_encode( $cMap, array( 'auto_dates' ) ),
				php_xmlrpc_encode( $checkType )
			);

			return $this->methodCaller( "ContactService.addWithDupCheck", $carray );
		}

		/**
		 * @service Credit Card Submission Service
		 */

		/**
		 * @method requestSubmissionToken
		 * @description gets a token, which is needed to POST a credit card to the application
		 *
		 * @param int $contactId
		 * @param string $successUrl
		 * @param string $failureUrl
		 *
		 * @return string
		 */
		public function requestCcSubmissionToken( $contactId, $successUrl, $failureUrl ) {
			$carray = array(
				php_xmlrpc_encode( (int) $contactId ),
				php_xmlrpc_encode( (string) $successUrl ),
				php_xmlrpc_encode( (string) $failureUrl )
			);

			return $this->methodCaller( "CreditCardSubmissionService.requestSubmissionToken", $carray );
		}

		/**
		 * @method requestCreditCardId
		 * @description retrieves credit card details (CC number not included) that have been posted to the app
		 *
		 * @param $token
		 *
		 * @return array
		 */
		public function requestCreditCardId( $token ) {
			$carray = array(
				php_xmlrpc_encode( $token )
			);

			return $this->methodCaller( "CreditCardSubmissionService.requestCreditCardId", $carray );
		}

		/**
		 * @service Data Service
		 */

		/**
		 * @method getAppSetting
		 * @description gets an app setting
		 *
		 * @param string $module
		 * @param string $setting
		 *
		 * @return int|mixed|string
		 */
		public function dsGetSetting( $module, $setting ) {
			$carray = array(
				php_xmlrpc_encode( $module ),
				php_xmlrpc_encode( $setting )
			);

			return $this->methodCaller( "DataService.getAppSetting", $carray );
		}

		/**
		 * @method add
		 * @description Add a record to a table
		 *
		 * @param string $tName
		 * @param array $iMap
		 *
		 * @return int
		 */
		public function dsAdd( $tName, $iMap ) {
			$carray = array(
				php_xmlrpc_encode( $tName ),
				php_xmlrpc_encode( $iMap, array( 'auto_dates' ) )
			);

			return $this->methodCaller( "DataService.add", $carray );
		}

		/**
		 * @method dsAddWithImage
		 * @description Add a record to a table that includes an image
		 *
		 * @param string $tName
		 * @param array $iMap
		 *
		 * @return int
		 */
		public function dsAddWithImage( $tName, $iMap ) {
			$carray = array(
				php_xmlrpc_encode( $tName ),
				php_xmlrpc_encode( $iMap, array( 'auto_dates', 'auto_base64' ) )
			);

			return $this->methodCaller( "DataService.add", $carray );
		}

		/**
		 * @method delete
		 * @description delete a record from Infusionsoft
		 *
		 * @param string $tName
		 * @param int $id
		 *
		 * @return bool
		 */
		public function dsDelete( $tName, $id ) {
			$carray = array(
				php_xmlrpc_encode( $tName ),
				php_xmlrpc_encode( (int) $id )
			);

			return $this->methodCaller( "DataService.delete", $carray );
		}

		/**
		 * @method update
		 * @description Update a record in any table
		 *
		 * @param string $tName
		 * @param int $id
		 * @param array $iMap
		 *
		 * @return int
		 */
		public function dsUpdate( $tName, $id, $iMap ) {
			$carray = array(
				php_xmlrpc_encode( $tName ),
				php_xmlrpc_encode( (int) $id ),
				php_xmlrpc_encode( $iMap, array( 'auto_dates' ) )
			);

			return $this->methodCaller( "DataService.update", $carray );
		}

		/**
		 * @method dsUpdateWithImage
		 * @description Update a record in any table with an image
		 *
		 * @param string $tName
		 * @param int $id
		 * @param array $iMap
		 *
		 * @return int
		 */
		public function dsUpdateWithImage( $tName, $id, $iMap ) {
			$carray = array(
				php_xmlrpc_encode( $tName ),
				php_xmlrpc_encode( (int) $id ),
				php_xmlrpc_encode( $iMap, array( 'auto_dates', 'auto_base64' ) )
			);

			return $this->methodCaller( "DataService.update", $carray );
		}

		/**
		 * @method load
		 * @description Load a record from any table
		 *
		 * @param string $tName
		 * @param int $id
		 * @param array $rFields
		 *
		 * @return array
		 */
		public function dsLoad( $tName, $id, $rFields ) {
			$carray = array(
				php_xmlrpc_encode( $tName ),
				php_xmlrpc_encode( (int) $id ),
				php_xmlrpc_encode( $rFields )
			);

			return $this->methodCaller( "DataService.load", $carray );
		}

		/**
		 * @method findByField
		 * @description finds records by searching a specific field
		 *
		 * @param string $tName
		 * @param int $limit
		 * @param int $page
		 * @param string $field
		 * @param string $value
		 * @param array $rFields
		 *
		 * @return array
		 */
		public function dsFind( $tName, $limit, $page, $field, $value, $rFields ) {
			$carray = array(
				php_xmlrpc_encode( $tName ),
				php_xmlrpc_encode( (int) $limit ),
				php_xmlrpc_encode( (int) $page ),
				php_xmlrpc_encode( $field ),
				php_xmlrpc_encode( $value ),
				php_xmlrpc_encode( $rFields )
			);

			return $this->methodCaller( "DataService.findByField", $carray );
		}

		/**
		 * @method query
		 * @description Finds records based on query
		 *
		 * @param string $tName
		 * @param int $limit
		 * @param int $page
		 * @param array $query
		 * @param array $rFields
		 *
		 * @return array
		 */
		public function dsQuery( $tName, $limit, $page, $query, $rFields ) {
			$carray = array(
				php_xmlrpc_encode( $tName ),
				php_xmlrpc_encode( (int) $limit ),
				php_xmlrpc_encode( (int) $page ),
				php_xmlrpc_encode( $query, array( 'auto_dates' ) ),
				php_xmlrpc_encode( $rFields )
			);

			return $this->methodCaller( "DataService.query", $carray );
		}

		/**
		 * @method queryWithOrderBy
		 * @description Finds records based on query with option to sort
		 *
		 * @param string $tName
		 * @param int $limit
		 * @param int $page
		 * @param array $query
		 * @param array $rFields
		 * @param string $orderByField
		 * @param bool $ascending
		 *
		 * @return array
		 */
		public function dsQueryOrderBy( $tName, $limit, $page, $query, $rFields, $orderByField, $ascending = true ) {
			$carray = array(
				php_xmlrpc_encode( $tName ),
				php_xmlrpc_encode( (int) $limit ),
				php_xmlrpc_encode( (int) $page ),
				php_xmlrpc_encode( $query, array( 'auto_dates' ) ),
				php_xmlrpc_encode( $rFields ),
				php_xmlrpc_encode( $orderByField ),
				php_xmlrpc_encode( (bool) $ascending )
			);

			return $this->methodCaller( "DataService.query", $carray );
		}

		/**
		 * @method DataService.Count
		 * @description Gets record count based on query
		 *
		 * @param string $tName
		 * @param array $query
		 *
		 * @return int
		 */

		public function dsCount( $tName, $query ) {
			$carray = array(
				php_xmlrpc_encode( $tName ),
				php_xmlrpc_encode( $query, array( 'auto_dates' ) )
			);

			return $this->methodCaller( "DataService.count", $carray );
		}

		/**
		 * @method addCustomField
		 * @description adds a custom field
		 *
		 * @param string $context
		 * @param string $displayName
		 * @param int $dataType
		 * @param int $headerID
		 *
		 * @return int
		 */
		public function addCustomField( $context, $displayName, $dataType, $headerID ) {
			$carray = array(

				php_xmlrpc_encode( $context ),
				php_xmlrpc_encode( $displayName ),
				php_xmlrpc_encode( $dataType ),
				php_xmlrpc_encode( (int) $headerID )
			);

			return $this->methodCaller( "DataService.addCustomField", $carray );
		}

		/**
		 * @method authenticateUser
		 * @description Authenticates a user account in Infusionsoft
		 *
		 * @param string $userName
		 * @param string $password
		 *
		 * @return int
		 */
		public function authenticateUser( $userName, $password ) {
			$password = strtolower( md5( $password ) );
			$carray   = array(
				php_xmlrpc_encode( $userName ),
				php_xmlrpc_encode( $password )
			);

			return $this->methodCaller( "DataService.authenticateUser", $carray );
		}

		/**
		 * @method - updateCustomField
		 * @description update a custom field
		 *
		 * @param int $fieldId
		 * @param array $fieldValues
		 *
		 * @return int
		 */
		public function updateCustomField( $fieldId, $fieldValues ) {
			$carray = array(
				php_xmlrpc_encode( (int) $fieldId ),
				php_xmlrpc_encode( $fieldValues )
			);

			return $this->methodCaller( "DataService.updateCustomField", $carray );
		}

		/**
		 * @service Discount Service
		 */

		/**
		 * @method addFreeTrial
		 * @description creates a subscription free trial for the shopping cart
		 *
		 * @param string $name
		 * @param string $description
		 * @param int $freeTrialDays
		 * @param int $hidePrice
		 * @param int $subscriptionPlanId
		 *
		 * @return int
		 */
		public function addFreeTrial( $name, $description, $freeTrialDays, $hidePrice, $subscriptionPlanId ) {
			$carray = array(
				php_xmlrpc_encode( (string) $name ),
				php_xmlrpc_encode( (string) $description ),
				php_xmlrpc_encode( (int) $freeTrialDays ),
				php_xmlrpc_encode( (int) $hidePrice ),
				php_xmlrpc_encode( (int) $subscriptionPlanId )
			);

			return $this->methodCaller( "DiscountService.addFreeTrial", $carray );
		}

		/**
		 * @method getFreeTrial
		 * @description retrieves the details on the given free trial
		 *
		 * @param int $trialId
		 *
		 * @return array
		 */
		public function getFreeTrial( $trialId ) {
			$carray = array(
				php_xmlrpc_encode( (int) $trialId )
			);

			return $this->methodCaller( "DiscountService.getFreeTrial", $carray );
		}

		/**
		 * @method addOrderTotalDiscount
		 * @description creates an order total discount for the shopping cart
		 *
		 * @param string $name
		 * @param string $description
		 * @param int $applyDiscountToCommission
		 * @param int $percentOrAmt
		 * @paramOption 0 Amount
		 * @paramOption 1 Percent
		 * @param double $amt
		 * @param string $payType
		 * @paramOption Gross
		 * @paramOption Net
		 *
		 * @return int
		 */
		public function addOrderTotalDiscount( $name, $description, $applyDiscountToCommission, $percentOrAmt, $amt, $payType ) {
			$carray = array(
				php_xmlrpc_encode( (string) $name ),
				php_xmlrpc_encode( (string) $description ),
				php_xmlrpc_encode( (int) $applyDiscountToCommission ),
				php_xmlrpc_encode( (int) $percentOrAmt ),
				php_xmlrpc_encode( $amt ),
				php_xmlrpc_encode( $payType )
			);

			return $this->methodCaller( "DiscountService.addOrderTotalDiscount", $carray );
		}

		/**
		 * @method getOrderTotalDiscount
		 * @description retrieves the details on the given order total discount
		 *
		 * @param int $id
		 *
		 * @return array
		 */
		public function getOrderTotalDiscount( $id ) {
			$carray = array(
				php_xmlrpc_encode( (int) $id )
			);

			return $this->methodCaller( "DiscountService.getOrderTotalDiscount", $carray );
		}

		/**
		 * @method addCategoryDiscount
		 * @description creates a product category discount for the shopping cart
		 *
		 * @param string $name
		 * @param string $description
		 * @param int $applyDiscountToCommission
		 * @param double $amt
		 *
		 * @return int
		 */
		public function addCategoryDiscount( $name, $description, $applyDiscountToCommission, $amt ) {
			$carray = array(
				php_xmlrpc_encode( (string) $name ),
				php_xmlrpc_encode( (string) $description ),
				php_xmlrpc_encode( (int) $applyDiscountToCommission ),
				php_xmlrpc_encode( $amt )
			);

			return $this->methodCaller( "DiscountService.addCategoryDiscount", $carray );
		}

		/**
		 * @method getCategoryDiscount
		 * @description retrieves the details on the Category discount
		 *
		 * @param int $id
		 *
		 * @return array
		 */
		public function getCategoryDiscount( $id ) {
			$carray = array(
				php_xmlrpc_encode( (int) $id )
			);

			return $this->methodCaller( "DiscountService.getCategoryDiscount", $carray );
		}

		/**
		 * @method addCategoryAssignmentToCategoryDiscount
		 * @description assigns a product category to a particular category discount
		 *
		 * @param int $categoryDiscountId
		 * @param int $productCategoryId
		 *
		 * @return int
		 */
		public function addCategoryAssignmentToCategoryDiscount( $categoryDiscountId, $productCategoryId ) {
			$carray = array(
				php_xmlrpc_encode( (int) $categoryDiscountId ),
				php_xmlrpc_encode( (int) $productCategoryId )
			);

			return $this->methodCaller( "DiscountService.addCategoryAssignmentToCategoryDiscount", $carray );
		}

		/**
		 * @method getCategoryAssignmentsForCategoryDiscount
		 * @description retrieves the product categories that are currently set for the given category discount
		 *
		 * @param int $id
		 *
		 * @return array
		 */
		public function getCategoryAssignmentsForCategoryDiscount( $id ) {
			$carray = array(
				php_xmlrpc_encode( (int) $id )
			);

			return $this->methodCaller( "DiscountService.getCategoryAssignmentsForCategoryDiscount", $carray );
		}

		/**
		 * @method addProductTotalDiscount
		 * @description creates a product total discount for the shopping cart
		 *
		 * @param string $name
		 * @param string $description
		 * @param int $applyDiscountToCommission
		 * @param int $productId
		 * @param int $percentOrAmt
		 * @paramOption 0 Amount
		 * @paramOption 1 Percent
		 * @param double $amt
		 *
		 * @return int
		 */
		public function addProductTotalDiscount( $name, $description, $applyDiscountToCommission, $productId, $percentOrAmt, $amt ) {
			$carray = array(
				php_xmlrpc_encode( (string) $name ),
				php_xmlrpc_encode( (string) $description ),
				php_xmlrpc_encode( (int) $applyDiscountToCommission ),
				php_xmlrpc_encode( (int) $productId ),
				php_xmlrpc_encode( (int) $percentOrAmt ),
				php_xmlrpc_encode( $amt )
			);

			return $this->methodCaller( "DiscountService.addProductTotalDiscount", $carray );
		}

		/**
		 * @method getProductTotalDiscount
		 * @description retrieves the details on the given product total discount
		 *
		 * @param int $id
		 *
		 * @return array
		 */
		public function getProductTotalDiscount( $id ) {
			$carray = array(
				php_xmlrpc_encode( (int) $id )
			);

			return $this->methodCaller( "DiscountService.getProductTotalDiscount", $carray );
		}

		/**
		 * @method addShippingTotalDiscount
		 * @description creates a shipping total discount for the shopping cart
		 *
		 * @param string $name
		 * @param string $description
		 * @param int $applyDiscountToCommission
		 * @param int $percentOrAmt
		 * @paramOption 0 Amount
		 * @paramOption 1 Percent
		 * @param double $amt
		 *
		 * @return int
		 */
		public function addShippingTotalDiscount( $name, $description, $applyDiscountToCommission, $percentOrAmt, $amt ) {
			$carray = array(
				php_xmlrpc_encode( (string) $name ),
				php_xmlrpc_encode( (string) $description ),
				php_xmlrpc_encode( (int) $applyDiscountToCommission ),
				php_xmlrpc_encode( (int) $percentOrAmt ),
				php_xmlrpc_encode( $amt )
			);

			return $this->methodCaller( "DiscountService.addShippingTotalDiscount", $carray );
		}

		/**
		 * @method getShippingTotalDiscount
		 * @description retrieves the details on the given shipping total discount
		 *
		 * @param int $id
		 *
		 * @return array
		 */
		public function getShippingTotalDiscount( $id ) {
			$carray = array(
				php_xmlrpc_encode( (int) $id )
			);

			return $this->methodCaller( "DiscountService.getShippingTotalDiscount", $carray );
		}

		/**
		 * @service API Email Service
		 */

		/**
		 * @method attachEmail
		 * @description attachs an email to a contacts email history
		 *
		 * @param int $cId
		 * @param string $fromName
		 * @param string $fromAddress
		 * @param string $toAddress
		 * @param string $ccAddresses
		 * @param string $bccAddresses
		 * @param string $contentType
		 * @param string $subject
		 * @param string $htmlBody
		 * @param string $txtBody
		 * @param string $header
		 * @param date $strRecvdDate
		 * @param date $strSentDate
		 * @param int $emailSentType
		 *
		 * @return bool
		 */
		public function attachEmail(
			$cId, $fromName, $fromAddress, $toAddress, $ccAddresses,
			$bccAddresses, $contentType, $subject, $htmlBody, $txtBody,
			$header, $strRecvdDate, $strSentDate, $emailSentType = 1
		) {
			$carray = array(
				php_xmlrpc_encode( (int) $cId ),
				php_xmlrpc_encode( $fromName ),
				php_xmlrpc_encode( $fromAddress ),
				php_xmlrpc_encode( $toAddress ),
				php_xmlrpc_encode( $ccAddresses ),
				php_xmlrpc_encode( $bccAddresses ),
				php_xmlrpc_encode( $contentType ),
				php_xmlrpc_encode( $subject ),
				php_xmlrpc_encode( $htmlBody ),
				php_xmlrpc_encode( $txtBody ),
				php_xmlrpc_encode( $header ),
				php_xmlrpc_encode( $strRecvdDate ),
				php_xmlrpc_encode( $strSentDate ),
				php_xmlrpc_encode( $emailSentType )
			);

			return $this->methodCaller( "APIEmailService.attachEmail", $carray );
		}

		/**
		 * @method getAvailableMergeFields
		 * @description gets a list of all available merge fields
		 *
		 * @param string $mergeContext
		 *
		 * @return array
		 */
		public function getAvailableMergeFields( $mergeContext ) {
			$carray = array(
				php_xmlrpc_encode( $mergeContext )
			);

			return $this->methodCaller( "APIEmailService.getAvailableMergeFields", $carray );
		}

		/**
		 * @method sendEmail
		 * @description send an email to a list of contacts
		 *
		 * @param array $conList
		 * @param string $fromAddress
		 * @param string $toAddress
		 * @param string $ccAddresses
		 * @param string $bccAddresses
		 * @param string $contentType
		 * @param string $subject
		 * @param string $htmlBody
		 * @param string $txtBody
		 *
		 * @return bool
		 */
		public function sendEmail( $conList, $fromAddress, $toAddress, $ccAddresses, $bccAddresses, $contentType, $subject, $htmlBody, $txtBody ) {
			$carray = array(
				php_xmlrpc_encode( $conList ),
				php_xmlrpc_encode( $fromAddress ),
				php_xmlrpc_encode( $toAddress ),
				php_xmlrpc_encode( $ccAddresses ),
				php_xmlrpc_encode( $bccAddresses ),
				php_xmlrpc_encode( $contentType ),
				php_xmlrpc_encode( $subject ),
				php_xmlrpc_encode( $htmlBody ),
				php_xmlrpc_encode( $txtBody )
			);

			return $this->methodCaller( "APIEmailService.sendEmail", $carray );
		}

		/**
		 * @method sendTemplate
		 * @description sends a template to a list of contacts
		 * @note uses APIEmailService.sendEmail with different parameters
		 *
		 * @param array $conList
		 * @param int $template
		 *
		 * @return bool
		 */
		public function sendTemplate( $conList, $template ) {
			$carray = array(
				php_xmlrpc_encode( $conList ),
				php_xmlrpc_encode( $template )
			);

			return $this->methodCaller( "APIEmailService.sendEmail", $carray );
		}

		/**
		 * @note THIS IS DEPRECATED - USE addEmailTemplate instead!
		 * @method createEmailTemplate
		 * @description Creates a legacy Email Template
		 *
		 * @param string $title
		 * @param int $userID
		 * @param string $fromAddress
		 * @param string $toAddress
		 * @param string $ccAddresses
		 * @param string $bccAddresses
		 * @param string $contentType
		 * @param string $subject
		 * @param string $htmlBody
		 * @param string $txtBody
		 *
		 * @return int
		 */
		public function createEmailTemplate(
			$title, $userID, $fromAddress, $toAddress, $ccAddresses, $bccAddresses, $contentType, $subject, $htmlBody,
			$txtBody
		) {
			$carray = array(
				php_xmlrpc_encode( $title ),
				php_xmlrpc_encode( $category = '' ),
				php_xmlrpc_encode( $fromAddress ),
				php_xmlrpc_encode( $toAddress ),
				php_xmlrpc_encode( $ccAddresses ),
				php_xmlrpc_encode( $bccAddresses ),
				php_xmlrpc_encode( $subject ),
				php_xmlrpc_encode( $txtBody ),
				php_xmlrpc_encode( $htmlBody ),
				php_xmlrpc_encode( $contentType ),
				php_xmlrpc_encode( $mergeContext = 'Contact' )
			);

			return $this->methodCaller( "APIEmailService.addEmailTemplate", $carray );
		}

		/**
		 * @method addEmailTemplate
		 * @description creates an Email Template
		 *
		 * @param string $title
		 * @param string $category
		 * @param string $fromAddress
		 * @param string $toAddress
		 * @param string $ccAddresses
		 * @param string $bccAddresses
		 * @param string $subject
		 * @param string $txtBody
		 * @param string $htmlBody
		 * @param string $contentType
		 * @param string $mergeContext
		 *
		 * @return int
		 */
		public function addEmailTemplate( $title, $category, $fromAddress, $toAddress, $ccAddresses, $bccAddresses, $subject, $txtBody, $htmlBody, $contentType, $mergeContext ) {
			$carray = array(
				php_xmlrpc_encode( $title ),
				php_xmlrpc_encode( $category ),
				php_xmlrpc_encode( $fromAddress ),
				php_xmlrpc_encode( $toAddress ),
				php_xmlrpc_encode( $ccAddresses ),
				php_xmlrpc_encode( $bccAddresses ),
				php_xmlrpc_encode( $subject ),
				php_xmlrpc_encode( $txtBody ),
				php_xmlrpc_encode( $htmlBody ),
				php_xmlrpc_encode( $contentType ),
				php_xmlrpc_encode( $mergeContext )
			);

			return $this->methodCaller( "APIEmailService.addEmailTemplate", $carray );
		}

		/**
		 * @method getEmailTemplate
		 * @description get the HTML of an email template
		 *
		 * @param int $templateId
		 *
		 * @return array
		 */
		public function getEmailTemplate( $templateId ) {
			$carray = array(
				php_xmlrpc_encode( (int) $templateId )
			);

			return $this->methodCaller( "APIEmailService.getEmailTemplate", $carray );
		}

		/**
		 * @method updateEmailTemplate
		 * @description Update an Email template
		 *
		 * @param int $templateID
		 * @param string $title
		 * @param string $categories
		 * @param string $fromAddress
		 * @param string $toAddress
		 * @param string $ccAddress
		 * @param string $bccAddress
		 * @param string $subject
		 * @param string $textBody
		 * @param string $htmlBody
		 * @param string $contentType
		 * @param string $mergeContext
		 *
		 * @return bool
		 */
		public function updateEmailTemplate( $templateID, $title, $categories, $fromAddress, $toAddress, $ccAddress, $bccAddress, $subject, $textBody, $htmlBody, $contentType, $mergeContext ) {
			$carray = array(
				php_xmlrpc_encode( (int) $templateID ),
				php_xmlrpc_encode( $title ),
				php_xmlrpc_encode( $categories ),
				php_xmlrpc_encode( $fromAddress ),
				php_xmlrpc_encode( $toAddress ),
				php_xmlrpc_encode( $ccAddress ),
				php_xmlrpc_encode( $bccAddress ),
				php_xmlrpc_encode( $subject ),
				php_xmlrpc_encode( $textBody ),
				php_xmlrpc_encode( $htmlBody ),
				php_xmlrpc_encode( $contentType ),
				php_xmlrpc_encode( $mergeContext )
			);

			return $this->methodCaller( "APIEmailService.updateEmailTemplate", $carray );
		}

		/**
		 * @method getOptStatus
		 * @description get the Opt status of an email
		 *
		 * @param string $email
		 *
		 * @return int
		 */
		public function optStatus( $email ) {
			$carray = array(
				php_xmlrpc_encode( $email )
			);

			return $this->methodCaller( "APIEmailService.getOptStatus", $carray );
		}

		/**
		 * @method optIn
		 * @description Opts an email in to allow emails to be sent to them
		 * @note  Opt-In will only work on "non-marketable contacts not opted out people
		 *
		 * @param string $email
		 * @param string $reason
		 *
		 * @return bool
		 */
		public function optIn( $email, $reason = 'Contact Was Opted In through the API' ) {
			$carray = array(
				php_xmlrpc_encode( $email ),
				php_xmlrpc_encode( $reason )
			);

			return $this->methodCaller( "APIEmailService.optIn", $carray );
		}

		/**
		 * @method optOut
		 * @description Opts an email out. Emails will not be sent to them anymore
		 *
		 * @param string $email
		 * @param string $reason
		 *
		 * @return bool
		 */
		public function optOut( $email, $reason = 'Contact Was Opted Out through the API' ) {
			$carray = array(
				php_xmlrpc_encode( $email ),
				php_xmlrpc_encode( $reason )
			);

			return $this->methodCaller( "APIEmailService.optOut", $carray );
		}

		/**
		 * @service File Service
		 */

		/**
		 * @method getFile
		 * @description Gets File
		 *
		 * @param int $fileID
		 *
		 * @return base64 encoded file data
		 */
		public function getFile( $fileID ) {

			$carray = array(
				php_xmlrpc_encode( (int) $fileID )
			);
			$result = $this->methodCaller( "FileService.getFile", $carray );

			return $result;
		}

		/**
		 * @method uploadFile
		 * @description Upload a file to Infusionsoft
		 *
		 * @param string $fileName
		 * @param string $base64Enc
		 * @param int $cid
		 *
		 * @return int|mixed|string
		 */
		public function uploadFile( $fileName, $base64Enc, $cid = 0 ) {
			$result = 0;
			if ( $cid == 0 ) {
				$carray = array(
					php_xmlrpc_encode( $fileName ),
					php_xmlrpc_encode( $base64Enc )
				);
				$result = $this->methodCaller( "FileService.uploadFile", $carray );
			} else {
				$carray = array(
					php_xmlrpc_encode( (int) $cid ),
					php_xmlrpc_encode( $fileName ),
					php_xmlrpc_encode( $base64Enc )
				);
				$result = $this->methodCaller( "FileService.uploadFile", $carray );
			}

			return $result;
		}

		/**
		 * @method replaceFile
		 * @description replaces existing file
		 *
		 * @param int $fileID
		 * @param string $base64Enc
		 *
		 * @return bool
		 */
		public function replaceFile( $fileID, $base64Enc ) {
			$carray = array(
				php_xmlrpc_encode( (int) $fileID ),
				php_xmlrpc_encode( $base64Enc )
			);
			$result = $this->methodCaller( "FileService.replaceFile", $carray );

			return $result;
		}

		/**
		 * @method renameFile
		 * @description rename existing file
		 *
		 * @param int $fileID
		 * @param string $fileName
		 *
		 * @return bool
		 */
		public function renameFile( $fileID, $fileName ) {
			$carray = array(
				php_xmlrpc_encode( (int) $fileID ),
				php_xmlrpc_encode( $fileName )
			);
			$result = $this->methodCaller( "FileService.renameFile", $carray );

			return $result;
		}

		/**
		 * @method getDownloadUrl
		 * @description gets download url for public files
		 *
		 * @param int $fileID
		 *
		 * @return string
		 */
		public function getDownloadUrl( $fileID ) {
			$carray = array(
				php_xmlrpc_encode( (int) $fileID )
			);
			$result = $this->methodCaller( "FileService.getDownloadUrl", $carray );

			return $result;
		}

		/**
		 * @service Funnel Service
		 */

		/**
		 * @method achieveGoal
		 * @description achieves an api goal inside of the Campaign Builder to start a campaign
		 *
		 * @param string $integration
		 * @param string $callName
		 * @param int $contactId
		 *
		 * @return array
		 */
		public function achieveGoal( $integration, $callName, $contactId ) {
			$carray = array(
				php_xmlrpc_encode( (string) $integration ),
				php_xmlrpc_encode( (string) $callName ),
				php_xmlrpc_encode( (int) $contactId )
			);

			return $this->methodCaller( "FunnelService.achieveGoal", $carray );
		}

		/**
		 * @service Invoice Service
		 */

		/**
		 * @method deleteInvoice
		 * @description deletes an invoice
		 *
		 * @param int $Id
		 *
		 * @return bool
		 */
		public function deleteInvoice( $Id ) {
			$carray = array(
				php_xmlrpc_encode( (int) $Id )
			);

			return $this->methodCaller( "InvoiceService.deleteInvoice", $carray );
		}

		/**
		 * @method deleteSubscriptioin
		 * @description Delete a Subscription created through the API
		 *
		 * @param $Id
		 *
		 * @return bool
		 */
		public function deleteSubscription( $Id ) {
			$carray = array(
				php_xmlrpc_encode( (int) $Id )
			);

			return $this->methodCaller( "InvoiceService.deleteSubscription", $carray );
		}

		/**
		 * @method getPayments
		 * @description Get a list of payments on an invoice
		 *
		 * @param $Id
		 *
		 * @return array
		 */
		public function getPayments( $Id ) {
			$carray = array(
				php_xmlrpc_encode( (int) $Id )
			);

			return $this->methodCaller( "InvoiceService.getPayments", $carray );
		}

		/**
		 * @method setInvoiceSyncStatus
		 * @description sets the sync status column on the Invoice table
		 *
		 * @param $Id
		 * @param $syncStatus
		 *
		 * @return bool
		 */
		public function setInvoiceSyncStatus( $Id, $syncStatus ) {
			$carray = array(
				php_xmlrpc_encode( (int) $Id ),
				php_xmlrpc_encode( $syncStatus )
			);

			return $this->methodCaller( "InvoiceService.setInvoiceSyncStatus", $carray );
		}

		/**
		 * @method setPaymentSyncStatus
		 * @description sets the sync status column on the Payment table
		 *
		 * @param $Id
		 * @param $Status
		 *
		 * @return bool
		 */
		public function setPaymentSyncStatus( $Id, $Status ) {
			$carray = array(
				php_xmlrpc_encode( (int) $Id ),
				php_xmlrpc_encode( $Status )
			);

			return $this->methodCaller( "InvoiceService.setPaymentSyncStatus", $carray );
		}

		/**
		 * @method getPluginStatus
		 * @description Tells if the Ecommerce plugin is enabled
		 *
		 * @param string $className
		 *
		 * @return bool
		 */
		public function getPluginStatus( $className ) {
			$carray = array(
				php_xmlrpc_encode( $className )
			);

			return $this->methodCaller( "InvoiceService.getPluginStatus", $carray );
		}

		/**
		 * @method getAllPaymentOptions
		 * @description get a list of all Payment Options
		 * @return array
		 */
		public function getAllPaymentOptions() {
			$carray = array();

			return $this->methodCaller( "InvoiceService.getAllPaymentOptions", $carray );
		}

		/**
		 * @method addManualPayment
		 * @description add a manual payment to an invoice.
		 * @note Will not complete Purchase Goals or Successful Purchase Actions
		 *
		 * @param int $invId
		 * @param double $amt
		 * @param datetime $payDate
		 * @param datetime $payType
		 * @param string $payDesc
		 * @param bool $bypassComm
		 *
		 * @return int
		 */
		public function manualPmt( $invId, $amt, $payDate, $payType, $payDesc, $bypassComm ) {
			$carray = array(
				php_xmlrpc_encode( (int) $invId ),
				php_xmlrpc_encode( $amt ),
				php_xmlrpc_encode( $payDate, array( 'auto_dates' ) ),
				php_xmlrpc_encode( $payType ),
				php_xmlrpc_encode( $payDesc ),
				php_xmlrpc_encode( $bypassComm )
			);

			return $this->methodCaller( "InvoiceService.addManualPayment", $carray );
		}

		/**
		 * @method addOrderCommissionOverride
		 * @description Override Order Commissions
		 *
		 * @param int $invId
		 * @param int $affId
		 * @param int $prodId
		 * @param int $percentage
		 * @param double $amt
		 * @param int $payType
		 * @param string $desc
		 * @param date $date
		 *
		 * @return bool
		 */
		public function commOverride( $invId, $affId, $prodId, $percentage, $amt, $payType, $desc, $date ) {
			$carray = array(
				php_xmlrpc_encode( (int) $invId ),
				php_xmlrpc_encode( (int) $affId ),
				php_xmlrpc_encode( (int) $prodId ),
				php_xmlrpc_encode( $percentage ),
				php_xmlrpc_encode( $amt ),
				php_xmlrpc_encode( $payType ),
				php_xmlrpc_encode( $desc ),
				php_xmlrpc_encode( $date, array( 'auto_dates' ) )
			);

			return $this->methodCaller( "InvoiceService.addOrderCommissionOverride", $carray );
		}

		/**
		 * @method addOrderItem
		 * @description add a line item to an order
		 *
		 * @param int $ordId
		 * @param int $prodId
		 * @param int $type
		 * @paramOption 1 Shipping
		 * @paramOption 2 Tax
		 * @paramOption 3 Service & Misc
		 * @paramOption 4 Product
		 * @paramOption 5 Upsell Product
		 * @paramOption 6 Fiance Charge
		 * @paramOption 7 Special
		 * @paramOption 8 Program
		 * @paramOption 9 Subscription Plan
		 * @paramOption 10 Special:Free Trial Days
		 * @paramOption 12 Special: Order Total
		 * @paramOption 13 Special: Category
		 * @paramOption 14 Special: Shipping
		 * @param double $price
		 * @param itn $qty
		 * @param string $desc
		 * @param string $notes
		 *
		 * @return bool
		 */
		public function addOrderItem( $ordId, $prodId, $type, $price, $qty, $desc, $notes ) {
			$carray = array(
				php_xmlrpc_encode( (int) $ordId ),
				php_xmlrpc_encode( (int) $prodId ),
				php_xmlrpc_encode( (int) $type ),
				php_xmlrpc_encode( $price ),
				php_xmlrpc_encode( $qty ),
				php_xmlrpc_encode( $desc ),
				php_xmlrpc_encode( $notes )
			);

			return $this->methodCaller( "InvoiceService.addOrderItem", $carray );
		}

		/**
		 * @method addPaymentPlan
		 * @description add a payment plan to an order
		 *
		 * @param int $ordId
		 * @param bool $aCharge
		 * @param int $ccId
		 * @param int $merchId
		 * @param int $retry
		 * @param int $retryAmt
		 * @param double $initialPmt
		 * @param datetime $initialPmtDate
		 * @param datetime $planStartDate
		 * @param int $numPmts
		 * @param int $pmtDays
		 *
		 * @return bool
		 */
		public function payPlan( $ordId, $aCharge, $ccId, $merchId, $retry, $retryAmt, $initialPmt, $initialPmtDate, $planStartDate, $numPmts, $pmtDays ) {
			$carray = array(
				php_xmlrpc_encode( (int) $ordId ),
				php_xmlrpc_encode( $aCharge ),
				php_xmlrpc_encode( (int) $ccId ),
				php_xmlrpc_encode( (int) $merchId ),
				php_xmlrpc_encode( (int) $retry ),
				php_xmlrpc_encode( (int) $retryAmt ),
				php_xmlrpc_encode( $initialPmt ),
				php_xmlrpc_encode( $initialPmtDate, array( 'auto_dates' ) ),
				php_xmlrpc_encode( $planStartDate, array( 'auto_dates' ) ),
				php_xmlrpc_encode( (int) $numPmts ),
				php_xmlrpc_encode( (int) $pmtDays )
			);

			return $this->methodCaller( "InvoiceService.addPaymentPlan", $carray );
		}

		/**
		 * @method addRecurringOrder
		 * @description creates a subscription for a contact
		 *
		 * @param int $cid
		 * @param bool $allowDup
		 * @param int $progId
		 * @param int $merchId
		 * @param int $ccId
		 * @param int $affId
		 * @param  int $daysToCharge
		 *
		 * @return int
		 */
		public function addRecurring( $cid, $allowDup, $progId, $merchId, $ccId, $affId, $daysToCharge ) {
			$carray = array(
				php_xmlrpc_encode( (int) $cid ),
				php_xmlrpc_encode( $allowDup ),
				php_xmlrpc_encode( (int) $progId ),
				php_xmlrpc_encode( (int) $merchId ),
				php_xmlrpc_encode( (int) $ccId ),
				php_xmlrpc_encode( (int) $affId ),
				php_xmlrpc_encode( $daysToCharge )
			);

			return $this->methodCaller( "InvoiceService.addRecurringOrder", $carray );
		}

		/**
		 * @method addRecurringOrderAdv
		 * @description creates a subscription for a contact
		 * @note Allows Quantity, Price and Tax
		 *
		 * @param int $cid
		 * @param bool $allowDup
		 * @param int $progId
		 * @param int $qty
		 * @param double $price
		 * @param bool $allowTax
		 * @param int $merchId
		 * @param int $ccId
		 * @param int $affId
		 * @param int $daysToCharge
		 *
		 * @return int
		 */
		public function addRecurringAdv( $cid, $allowDup, $progId, $qty, $price, $allowTax, $merchId, $ccId, $affId, $daysToCharge ) {
			$carray = array(
				php_xmlrpc_encode( (int) $cid ),
				php_xmlrpc_encode( $allowDup ),
				php_xmlrpc_encode( (int) $progId ),
				php_xmlrpc_encode( $qty ),
				php_xmlrpc_encode( $price ),
				php_xmlrpc_encode( $allowTax ),
				php_xmlrpc_encode( $merchId ),
				php_xmlrpc_encode( (int) $ccId ),
				php_xmlrpc_encode( (int) $affId ),
				php_xmlrpc_encode( $daysToCharge )
			);

			return $this->methodCaller( "InvoiceService.addRecurringOrder", $carray );
		}

		/**
		 * @method calculateAmountOwed
		 * @description calculate amount owed on an invoice
		 *
		 * @param int $invId
		 *
		 * @return double
		 */
		public function amtOwed( $invId ) {
			$carray = array(
				php_xmlrpc_encode( (int) $invId )
			);

			return $this->methodCaller( "InvoiceService.calculateAmountOwed", $carray );
		}

		/**
		 * @method getInvoiceId
		 * @description get an Invoice Id attached to a one-time order
		 *
		 * @param int $orderId
		 *
		 * @return int
		 */
		public function getInvoiceId( $orderId ) {
			$carray = array(
				php_xmlrpc_encode( (int) $orderId )
			);

			return $this->methodCaller( "InvoiceService.getInvoiceId", $carray );
		}

		/**
		 * @method getOrderId
		 * @description get the Order Id associated with an Invoice
		 *
		 * @param int $invoiceId
		 *
		 * @return int
		 */
		public function getOrderId( $invoiceId ) {
			$carray = array(
				php_xmlrpc_encode( (int) $invoiceId )
			);

			return $this->methodCaller( "InvoiceService.getOrderId", $carray );
		}

		/**
		 * @method chargeInvoice
		 * @description Charges an invoice immediately
		 *
		 * @param int $invId
		 * @param string $notes
		 * @param int $ccId
		 * @param int $merchId
		 * @param bool $bypassComm
		 *
		 * @return array
		 */
		public function chargeInvoice( $invId, $notes, $ccId, $merchId, $bypassComm ) {
			$carray = array(
				php_xmlrpc_encode( (int) $invId ),
				php_xmlrpc_encode( $notes ),
				php_xmlrpc_encode( (int) $ccId ),
				php_xmlrpc_encode( (int) $merchId ),
				php_xmlrpc_encode( $bypassComm )
			);

			return $this->methodCaller( "InvoiceService.chargeInvoice", $carray );
		}

		/**
		 * @method createBlankOrder
		 * @description creates a blank order for a contact
		 *
		 * @param int $conId
		 * @param string $desc
		 * @param date $oDate
		 * @param int $leadAff
		 * @param int $saleAff
		 *
		 * @return int
		 */
		public function blankOrder( $conId, $desc, $oDate, $leadAff, $saleAff ) {
			$carray = array(
				php_xmlrpc_encode( (int) $conId ),
				php_xmlrpc_encode( $desc ),
				php_xmlrpc_encode( $oDate, array( 'auto_dates' ) ),
				php_xmlrpc_encode( (int) $leadAff ),
				php_xmlrpc_encode( (int) $saleAff )
			);

			return $this->methodCaller( "InvoiceService.createBlankOrder", $carray );
		}

		/**
		 * @method createInvoiceForRecurring
		 * @description creates an invoice for a subscription
		 *
		 * @param int $rid
		 *
		 * @return int
		 */
		public function recurringInvoice( $rid ) {
			$carray = array(
				php_xmlrpc_encode( (int) $rid )
			);

			return $this->methodCaller( "InvoiceService.createInvoiceForRecurring", $carray );
		}

		/**
		 * @method locateExistingCard
		 * @description locates a creditcard Id from based on the last 4 digits
		 *
		 * @param int $cid
		 * @param string $last4
		 *
		 * @return int
		 */
		public function locateCard( $cid, $last4 ) {
			$carray = array(
				php_xmlrpc_encode( (int) $cid ),
				php_xmlrpc_encode( $last4 )
			);

			return $this->methodCaller( "InvoiceService.locateExistingCard", $carray );
		}

		/**
		 * @method validateCreditCard
		 * @description Validates a Credit Card
		 * @note this will take a CC ID or a CC array
		 *
		 * @param mixed $creditCard
		 *
		 * @return int
		 */
		public function validateCard( $creditCard ) {
			$creditCard = is_array( $creditCard ) ? $creditCard : (int) $creditCard;

			$carray = array(
				php_xmlrpc_encode( $creditCard )
			);

			return $this->methodCaller( "InvoiceService.validateCreditCard", $carray );
		}

		/**
		 * @method updateSubscriptionNextBillDate
		 * @description Updates the Next Bill Date on a Subscription
		 *
		 * @param int $subscriptionId
		 * @param date $nextBillDate
		 *
		 * @return bool
		 */
		public function updateSubscriptionNextBillDate( $subscriptionId, $nextBillDate ) {
			$carray = array(
				php_xmlrpc_encode( (int) $subscriptionId ),
				php_xmlrpc_encode( $nextBillDate, array( 'auto_dates' ) )
			);

			return $this->methodCaller( "InvoiceService.updateJobRecurringNextBillDate", $carray );
		}

		/**
		 * @method recalculateTax
		 * @description recalculates tax for a given invoice Id
		 *
		 * @param $invoiceId
		 *
		 * @return bool
		 */
		public function recalculateTax( $invoiceId ) {
			$carray = array(
				php_xmlrpc_encode( (int) $invoiceId )
			);

			return $this->methodCaller( "InvoiceService.recalculateTax", $carray );
		}

		/**
		 * @service Misc WPF_iSDK Functions
		 */

		/**
		 * @method infuDate
		 * @description returns properly formatted dates.
		 *
		 * @param $dateStr
		 * @param $dateFrmt - Optional date format for UK formatted Applications
		 *
		 * @return bool|string
		 */
		public function infuDate( $dateStr, $dateFrmt = 'US' ) {
			$dArray = date_parse( $dateStr );
			if ( $dArray['error_count'] < 1 ) {
				$tStamp =
					mktime( $dArray['hour'], $dArray['minute'], $dArray['second'], $dArray['month'],
						$dArray['day'], $dArray['year'] );
				if ( $dateFrmt == 'UK' ) {
					setlocale( LC_ALL, 'en_GB' );

					return date( 'Y-d-m\TH:i:s', $tStamp );
				} else {
					return date( 'Ymd\TH:i:s', $tStamp );
				}
			} else {
				foreach ( $dArray['errors'] as $err ) {
					echo "ERROR: " . $err . "<br />";
				}
				die( "The above errors prevented the application from executing properly." );
			}
		}

		/**
		 * @method enableLogging
		 * @description Function to Enable/Disable Logging
		 *
		 * @param int $log
		 */
		public function enableLogging( $log ) {
			$this->loggingEnabled = $log;
		}

		/**
		 * @method getHandle
		 * @description Creates CSV Resource
		 *
		 * @param string $logname
		 *
		 * @return resource
		 */
		static protected function getHandle( $logname ) {
			if ( ! is_resource( self::$handle ) ) {
				self::$handle = fopen( $logname, 'a+' );
			}

			return self::$handle;
		}

		/**
		 * @method log
		 * @description Function for Logging Calls
		 *
		 * @param array $data
		 *
		 * @return mixed
		 */
		private function log( $data ) {
			$logdata = $data;

			if ( $this->logname == '' ) {
				$logname = dirname( __FILE__ ) . '/apilog.csv';
			} else {
				$logname = $this->logname;
			}

			if ( ! file_exists( $logname ) ) {
				$this->getHandle( $logname );
				fputcsv( self::$handle, array(
					'Date',
					'Method',
					'Call',
					'Start Time',
					'Stop Time',
					'Execution Time',
					'Result',
					'Error',
					'Error Code'
				) );
			} else {
				$this->getHandle( $logname );
			}

			if ( isset( $logdata['Call'][0]->me['string'] ) ) {
				if ( $logdata['Call'][0]->me['string'] == 'CreditCard' ) {
					unset( $logdata['Call'][1]->me['struct'] );
					$logdata['Call'][1]->me['struct'] = 'Data Removed For Security';
				}
			}

			$logdata['Call'][0]->me['string'] = 'APIKEY';

			fputcsv( self::$handle, array(
				date( 'Y-m-d H:i:s', $logdata['Now'] ),
				$logdata['Method'],
				print_r( serialize( $logdata['Call'] ), true ),
				$logdata['Start'],
				$logdata['Stop'],
				( $logdata['Stop'] - $logdata['Start'] ),
				print_r( serialize( $logdata['Result'] ), true ),
				$logdata['Error'],
				$logdata['ErrorCode']
			) );
			fclose( self::$handle );

		}

		public function setLog( $logPath ) {
			$this->logname = $logPath;
		}

		/**
		 * @service Order Service
		 */

		/**
		 * @method placeOrder
		 * @description Builds, creates and charges an order.
		 *
		 * @param int $contactId
		 * @param int $creditCardId
		 * @param int $payPlanId
		 * @param array $productIds
		 * @param array $subscriptionIds
		 * @param bool $processSpecials
		 * @param array $promoCodes
		 * @param int $leadAff
		 * @param int $saleAff
		 *
		 * @return array
		 */
		public function placeOrder( $contactId, $creditCardId, $payPlanId, $productIds, $subscriptionIds, $processSpecials, $promoCodes, $leadAff = 0, $saleAff = 0 ) {
			$carray = array(
				php_xmlrpc_encode( (int) $contactId ),
				php_xmlrpc_encode( (int) $creditCardId ),
				php_xmlrpc_encode( (int) $payPlanId ),
				php_xmlrpc_encode( $productIds ),
				php_xmlrpc_encode( $subscriptionIds ),
				php_xmlrpc_encode( $processSpecials ),
				php_xmlrpc_encode( $promoCodes ),
				php_xmlrpc_encode( (int) $leadAff ),
				php_xmlrpc_encode( (int) $saleAff )
			);

			return $this->methodCaller( "OrderService.placeOrder", $carray );
		}

		/**
		 * @service Product Service
		 */

		/**
		 * @method getInventory
		 * @description retrieves the current inventory level for a specific product
		 *
		 * @param int $productId
		 *
		 * @return int
		 */
		public function getInventory( $productId ) {
			$carray = array(
				php_xmlrpc_encode( (int) $productId )
			);

			return $this->methodCaller( "ProductService.getInventory", $carray );
		}

		/**
		 * @method incrementInventory
		 * @description increments current inventory level by 1
		 *
		 * @param int $productId
		 *
		 * @return bool
		 */
		public function incrementInventory( $productId ) {
			$carray = array(
				php_xmlrpc_encode( (int) $productId )
			);

			return $this->methodCaller( "ProductService.incrementInventory", $carray );
		}

		/**
		 * @method decrementInventory
		 * @description decrements current inventory level by 1
		 *
		 * @param int $productId
		 *
		 * @return bool
		 */
		function decrementInventory( $productId ) {
			$carray = array(
				php_xmlrpc_encode( (int) $productId )
			);

			return $this->methodCaller( "ProductService.decrementInventory", $carray );
		}

		/**
		 * @method increaseInventory
		 * @description increases inventory levels
		 *
		 * @param int $productId
		 * @param int $quantity
		 *
		 * @return bool
		 */
		public function increaseInventory( $productId, $quantity ) {
			$carray = array(
				php_xmlrpc_encode( (int) $productId ),
				php_xmlrpc_encode( (int) $quantity )
			);

			return $this->methodCaller( "ProductService.increaseInventory", $carray );
		}

		/**
		 * @method decreaseInventory
		 * @description decreases inventory levels
		 *
		 * @param int $productId
		 * @param int $quantity
		 *
		 * @return bool
		 */
		public function decreaseInventory( $productId, $quantity ) {
			$carray = array(
				php_xmlrpc_encode( (int) $productId ),
				php_xmlrpc_encode( (int) $quantity )
			);

			return $this->methodCaller( "ProductService.decreaseInventory", $carray );
		}

		/**
		 * @method deactivateCreditCard
		 * @description deactivate a credit card
		 *
		 * @param int $creditCardId
		 *
		 * @return bool
		 */
		public function deactivateCreditCard( $creditCardId ) {
			$carray = array(
				php_xmlrpc_encode( (int) $creditCardId )
			);

			return $this->methodCaller( "ProductService.deactivateCreditCard", $carray );
		}

		/**
		 * @service Search Service
		 */

		/**
		 * @method getSavedSearchResultsAllFields
		 * @description returns a saved search with all fields
		 *
		 * @param int $savedSearchId
		 * @param int $userId
		 * @param int $page
		 *
		 * @return array
		 */
		public function savedSearchAllFields( $savedSearchId, $userId, $page ) {
			$carray = array(
				php_xmlrpc_encode( (int) $savedSearchId ),
				php_xmlrpc_encode( (int) $userId ),
				php_xmlrpc_encode( (int) $page )
			);

			return $this->methodCaller( "SearchService.getSavedSearchResultsAllFields", $carray );
		}

		/**
		 * @method getSavedSearchResults
		 * @description returns a saved search with selected fields
		 *
		 * @param int $savedSearchId
		 * @param int $userId
		 * @param int $page
		 * @param array $fields
		 *
		 * @return array
		 */
		public function savedSearch( $savedSearchId, $userId, $page, $fields ) {
			$carray = array(
				php_xmlrpc_encode( (int) $savedSearchId ),
				php_xmlrpc_encode( (int) $userId ),
				php_xmlrpc_encode( (int) $page ),
				php_xmlrpc_encode( $fields )
			);

			return $this->methodCaller( "SearchService.getSavedSearchResults", $carray );
		}

		/**
		 * @method getAllReportColumns
		 * @description returns the fields available in a saved report
		 *
		 * @param int $savedSearchId
		 * @param int $userId
		 *
		 * @return array
		 */
		public function getAvailableFields( $savedSearchId, $userId ) {
			$carray = array(
				php_xmlrpc_encode( (int) $savedSearchId ),
				php_xmlrpc_encode( (int) $userId )
			);

			return $this->methodCaller( "SearchService.getAllReportColumns", $carray );
		}

		/**
		 * @method getDefaultQuickSearch
		 * @description returns the default quick search type for a user
		 *
		 * @param int $userId
		 *
		 * @return array
		 */
		public function getDefaultQuickSearch( $userId ) {
			$carray = array(
				php_xmlrpc_encode( (int) $userId )
			);

			return $this->methodCaller( "SearchService.getDefaultQuickSearch", $carray );
		}

		/**
		 * @method getAvailableQuickSearches
		 * @description returns the available quick search types
		 *
		 * @param int $userId
		 *
		 * @return array
		 */
		public function getQuickSearches( $userId ) {
			$carray = array(
				php_xmlrpc_encode( (int) $userId )
			);

			return $this->methodCaller( "SearchService.getAvailableQuickSearches", $carray );
		}

		/**
		 * @method quickSearch
		 * @description returns the results of a quick search
		 *
		 * @param int $quickSearchType
		 * @param int $userId
		 * @param string $filterData
		 * @param int $page
		 * @param int $limit
		 *
		 * @return array
		 */
		public function quickSearch( $quickSearchType, $userId, $filterData, $page, $limit ) {
			$carray = array(
				php_xmlrpc_encode( $quickSearchType ),
				php_xmlrpc_encode( (int) $userId ),
				php_xmlrpc_encode( $filterData ),
				php_xmlrpc_encode( (int) $page ),
				php_xmlrpc_encode( (int) $limit )
			);

			return $this->methodCaller( "SearchService.quickSearch", $carray );
		}

		/**
		 * @service Service Call Service
		 * @note also known as Ticket System. This service is deprecated
		 */

		/**
		 * @method addMoveNotes
		 * @description Adds move notes to existing tickets
		 *
		 * @param array $ticketList
		 * @param string $moveNotes
		 * @param int $moveToStageId
		 * @param int $notifyIds
		 *
		 * @return bool
		 */
		public function addMoveNotes( $ticketList, $moveNotes, $moveToStageId, $notifyIds ) {
			$carray = array(
				php_xmlrpc_encode( $ticketList ),
				php_xmlrpc_encode( $moveNotes ),
				php_xmlrpc_encode( $moveToStageId ),
				php_xmlrpc_encode( $notifyIds )
			);

			return $this->methodCaller( "ServiceCallService.addMoveNotes", $carray );
		}

		/**
		 * @method moveTicketStage
		 * @description Moves a Ticket Stage
		 *
		 * @param int $ticketID
		 * @param string $ticketStage
		 * @param string $moveNotes
		 * @param string $notifyIds
		 *
		 * @return bool
		 */
		public function moveTicketStage( $ticketID, $ticketStage, $moveNotes, $notifyIds ) {
			$carray = array(
				php_xmlrpc_encode( (int) $ticketID ),
				php_xmlrpc_encode( $ticketStage ),
				php_xmlrpc_encode( $moveNotes ),
				php_xmlrpc_encode( $notifyIds )
			);

			return $this->methodCaller( "ServiceCallService.moveTicketStage", $carray );
		}

		/**
		 * @service Shipping Service
		 */

		/**
		 * @method getAllShippingOptions
		 * @description get a list of shipping methods
		 * @return array
		 */
		public function getAllShippingOptions() {
			$carray = array();

			return $this->methodCaller( "ShippingService.getAllShippingOptions", $carray );
		}

		/**
		 * @method getAllConfiguredShippingOptions
		 * @description get a list of shipping methods
		 * @return array
		 */
		public function getAllConfiguredShippingOptions() {
			$carray = array();

			return $this->methodCaller( "ShippingService.getAllShippingOptions", $carray );
		}

		/**
		 * @method getFlatRateShippingOption
		 * @description retrieves details on a flat rate type shipping option
		 *
		 * @param int $optionId
		 *
		 * @return array
		 */
		public function getFlatRateShippingOption( $optionId ) {
			$carray = array(
				php_xmlrpc_encode( (int) $optionId )
			);

			return $this->methodCaller( "ShippingService.getFlatRateShippingOption", $carray );
		}

		/**
		 * @method getOrderTotalShippingOption
		 * @description retrieves details on a order total type shipping option
		 *
		 * @param int $optionId
		 *
		 * @return array
		 */
		public function getOrderTotalShippingOption( $optionId ) {
			$carray = array(
				php_xmlrpc_encode( (int) $optionId )
			);

			return $this->methodCaller( "ShippingService.getOrderTotalShippingOption", $carray );
		}

		/**
		 * @method getOrderTotalShippingRanges
		 * @description retrieves the pricing range details for the given Order Total shipping option
		 *
		 * @param int $optionId
		 *
		 * @return array
		 */
		public function getOrderTotalShippingRanges( $optionId ) {
			$carray = array(
				php_xmlrpc_encode( (int) $optionId )
			);

			return $this->methodCaller( "ShippingService.getOrderTotalShippingRanges", $carray );
		}

		/**
		 * @method getProductBasedShippingOption
		 * @description retrieves details on a product based type shipping option
		 *
		 * @param int $optionId
		 *
		 * @return array
		 */
		public function getProductBasedShippingOption( $optionId ) {
			$carray = array(
				php_xmlrpc_encode( (int) $optionId )
			);

			return $this->methodCaller( "ShippingService.getProductBasedShippingOption", $carray );
		}

		/**
		 * @method getProductShippingPricesForProductShippingOption
		 * @description retrieves the pricing for your per product shipping options
		 *
		 * @param int $optionId
		 *
		 * @return array
		 */
		public function getProductShippingPricesForProductShippingOption( $optionId ) {
			$carray = array(
				php_xmlrpc_encode( (int) $optionId )
			);

			return $this->methodCaller( "ShippingService.getProductShippingPricesForProductShippingOption", $carray );
		}

		/**
		 * @method getOrderQuantityShippingOption
		 * @description retrieves details on a order quantity type shipping option
		 *
		 * @param int $optionId
		 *
		 * @return array
		 */
		public function getOrderQuantityShippingOption( $optionId ) {
			$carray = array(
				php_xmlrpc_encode( (int) $optionId )
			);

			return $this->methodCaller( "ShippingService.getOrderQuantityShippingOption", $carray );
		}

		/**
		 * @method getWeightBasedShippingOption
		 * @description retrieves details on a weight based type shipping option
		 *
		 * @param int $optionId
		 *
		 * @return array
		 */
		public function getWeightBasedShippingOption( $optionId ) {
			$carray = array(
				php_xmlrpc_encode( (int) $optionId )
			);

			return $this->methodCaller( "ShippingService.getWeightBasedShippingOption", $carray );
		}

		/**
		 * @method getWeightBasedShippingRanges
		 * @description retrieves the weight ranges for a weight based type shipping option
		 *
		 * @param int $optionId
		 *
		 * @return array
		 */
		public function getWeightBasedShippingRanges( $optionId ) {
			$carray = array(
				php_xmlrpc_encode( (int) $optionId )
			);

			return $this->methodCaller( "ShippingService.getWeightBasedShippingRanges", $carray );
		}

		/**
		 * @method getUpsShippingOption
		 * @description retrieves the details around a UPS type shipping option
		 *
		 * @param int $optionId
		 *
		 * @return array
		 */
		public function getUpsShippingOption( $optionId ) {
			$carray = array(
				php_xmlrpc_encode( (int) $optionId )
			);

			return $this->methodCaller( "ShippingService.getUpsShippingOption", $carray );
		}

		/**
		 * @service Web Form Service
		 */

		/**
		 * @method getMap
		 * @description returns web form titles and Id numbers from the application
		 * @return array
		 */
		public function getWebFormMap() {
			$carray = array();

			return $this->methodCaller( "WebFormService.getMap", $carray );
		}

		/**
		 * @method getHTML
		 * @description returns the HTML for the given web form
		 *
		 * @param int $webFormId
		 *
		 * @return string
		 */
		public function getWebFormHtml( $webFormId = 0 ) {
			$carray = array(
				php_xmlrpc_encode( (int) $webFormId )
			);

			return $this->methodCaller( "WebFormService.getHTML", $carray );
		}

		/**
		 * @service Web Tracking Service
		 */

		/**
		 * @method getWebTrackingScriptTag
		 * @description returns the web tracking javascript code
		 * @return string
		 */
		public function getWebTrackingServiceTag() {
			$carray = array();

			return $this->methodCaller( "WebTrackingService.getWebTrackingScriptTag", $carray );
		}

		/**
		 * @method getWebTrackingScriptUrl
		 * @description returns the url for the web tracking code
		 * @return string
		 */
		public function getWebTrackingScriptUrl() {
			$carray = array();

			return $this->methodCaller( "WebTrackingService.getWebTrackingScriptUrl", $carray );
		}

	}
}
?>