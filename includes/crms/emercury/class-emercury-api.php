<?php

class WPF_Emercury_API {

	const API_URL   = 'https://panel.emercury.net/api.php';
	const API_ERROR = 'You need to set API key';

	private $apiKey;
	private $apiEmail;

	public function __construct( $apiEmail, $apiKey ) {
		$this->apiKey   = $apiKey;
		$this->apiEmail = $apiEmail;
	}

	public function getTags() {
		$this->checkApiParameters();
		$xml = '<?xml version="1.0" encoding="utf-8" ?>
			<request>
				<user mail="' . $this->apiEmail . '" API_key="' . $this->apiKey . '"></user>
				<method>getTags</method>
			</request>';

		return $this->sendRequest( $xml );

	}

	public function getSubscribersByTag( $tag, $audience_id ) {
		$this->checkApiParameters();
		$xml = '<?xml version="1.0" encoding="utf-8" ?>
			<request>
				<user mail="' . $this->apiEmail . '" API_key="' . $this->apiKey . '"></user>
				<method>getSubscribersByTag</method>
				<parameters>
					<tag>' . $tag . '</tag>
					<audience_id>' . $audience_id . '</audience_id>
				</parameters>
			</request>';

		return $this->sendRequest( $xml );

	}

	public function getSubscriberTags( $email ) {
		$this->checkApiParameters();
		$xml = '<?xml version="1.0" encoding="utf-8" ?>
			<request>
				<user mail="' . $this->apiEmail . '" API_key="' . $this->apiKey . '"></user>
				<method>getSubscriberTags</method>
				<parameters>
					<subscriber>
						<email>' . $email . '</email>
					</subscriber>
				</parameters>
			</request>';

		return $this->sendRequest( $xml );

	}

	public function addSubscriberTag( $tags, $email ) {
		$this->checkApiParameters();
		$xml = '<?xml version="1.0" encoding="utf-8" ?>
			<request>
				<user mail="' . $this->apiEmail . '" API_key="' . $this->apiKey . '"></user>
				<method>addSubscriberTag</method>
				<parameters>
					<subscriber>
						<email>' . $email . '</email>
						<tags>' . $tags . '</tags>
					</subscriber>
				</parameters>
			</request>';

		return $this->sendRequest( $xml );

	}
	public function deleteSubscriberTag( $tags, $email ) {
		$this->checkApiParameters();
		$xml = '<?xml version="1.0" encoding="utf-8" ?>
			<request>
				<user mail="' . $this->apiEmail . '" API_key="' . $this->apiKey . '"></user>
				<method>deleteSubscriberTag</method>
				<parameters>
					<subscriber>
						<email>' . $email . '</email>
						<tags>' . $tags . '</tags>
					</subscriber>
				</parameters>
			</request>';

		return $this->sendRequest( $xml );

	}

	public function addAudience( $name ) {
		$this->checkApiParameters();
		$xml = '<?xml version="1.0" encoding="utf-8" ?>
			<request>
				<user mail="' . $this->apiEmail . '" API_key="' . $this->apiKey . '"></user>
				<method>addAudiences</method>
				<parameters>
					<audience_name>
						<name>' . $name . '</name>
					</audience_name>
				</parameters>
			</request>';

		return $this->sendRequest( $xml );
	}

	public function getAudience() {
		$this->checkApiParameters();
		$xml = '<?xml version="1.0" encoding="utf-8"?>
			<request>
				<method>GetAudiences</method>
				<user mail="' . $this->apiEmail . '" API_key="' . $this->apiKey . '"></user>
			</request>';

		return $this->sendRequest( $xml );
	}

	public function updateCustomFields( $fields ) {
		$this->checkApiParameters();

		$result = '';
		foreach ( $fields as $key => $value ) {
			$result .= '
				<field>
					<id>' . $key . '</id>
					<name>' . $value . '</name>
				</field>
			';
		}

		$xml = '<?xml version="1.0" encoding="utf-8" ?>
			<request>
					<user mail="' . $this->apiEmail . '" API_key="' . $this->apiKey . '"></user>
					<method>updateCustomFields</method>
					<parameters>
						' . $result . '
					</parameters>
			</request>';

		return $this->sendRequest( $xml );
	}

	public function getCustomFields() {
		$this->checkApiParameters();
		$xml = '<?xml version="1.0" encoding="utf-8" ?>
			<request>
					<user mail="' . $this->apiEmail . '" API_key="' . $this->apiKey . '"></user>
					<method>getCustomFields</method>
			</request>';

		return $this->sendRequest( $xml );
	}

	public function getTrackingCode() {
		$this->checkApiParameters();
		$xml = '<?xml version="1.0" encoding="utf-8" ?>
			<request>
					<method>getTrackingCode</method>
					<user mail="' . $this->apiEmail . '" API_key="' . $this->apiKey . '" />
					<parameters>
					   <create>true</create>
					</parameters>
			</request>';

		return $this->sendRequest( $xml );
	}

	public function updateSubscribers( $data, $list_id ) {
		$this->checkApiParameters();

		$result = '';
		foreach ( $data as $key => $value ) {
			$result .= '<' . $key . '>' . $value . '</' . $key . '>';
		}

		$date = date( 'm/d/Y' );

		$xml = '<?xml version="1.0" encoding="utf-8" ?>
					<request>
						<method>UpdateSubscribers</method>
						<user mail="' . $this->apiEmail . '" API_key="' . $this->apiKey . '"></user>
						<parameters>
							<audience_id>' . $list_id . '</audience_id>
							<date_format_id>1</date_format_id>
							<subscriber>
								<optin_date>' . $date . '</optin_date>
								' . $result . '
							</subscriber>
						</parameters>
					</request>';

		return $this->sendRequest( $xml );
	}

	public function getSubscribers( $audience_id, $email ) {
		$this->checkApiParameters();
		$xml = '<?xml version="1.0" encoding="utf-8"?>
			<request>
				<method>GetSubscribers</method>
				<user mail="' . $this->apiEmail . '" API_key="' . $this->apiKey . '"></user>
				<parameters>
					<audience_id>' . $audience_id . '</audience_id>
					<emails>
						<email>' . $email . '</email>
					</emails>
				</parameters>
			</request>';

		return $this->sendRequest( $xml );
	}

	public function parameters( $request ) {
		$body            = [];
		$body['request'] = $request;

		$xml = simplexml_load_string( $request );

		if ( ! empty( $xml->parameters->path ) ) {
			$path = addslashes( $xml->parameters->path );
			if ( ! preg_match( '/^(ftp|https?)\:/i', $path ) ) {
				$body['file'] = '@' . $path;
			}
		}

		return array(
			'method'      => 'POST',
			'httpversion' => '1.0',
			'blocking'    => true,
			'sslverify'   => false,
			'body'        => $body,
		);
	}

	private function checkApiParameters() {
		if ( $this->apiKey === '' || $this->apiEmail === '' ) {
			throw new \Exception();
		}
	}

	/**
	* @return array
	*/
	private function sendRequest( $xml ) {
		$res = wp_safe_remote_request(
			self::API_URL,
			$this->parameters( $xml )
		);

		$output = [];
		if ( is_wp_error( $res ) ) {
			$output = [
				'code'    => 'error',
				'message' => $res->get_error_message(),
			];
			wpf_log( 'error', 0, $res->get_error_message() );
		} else {
			$res    = $res['body'];
			$output = [
				'code'    => 'ok',
				'message' => ( is_string( $res ) ) ? simplexml_load_string( $res ) : $res,
			];
		}
		return $output;
	}
}
