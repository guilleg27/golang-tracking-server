<?php

public function macros()
{
	return array(
		'{app}'            => $this->app ? urlencode($this->app) : '',
		'{carrier}'        => $this->carrier!='-' ? urlencode($this->carrier) : '',
		'{click_id}'       => $this->id,
		'{campaign_id}'    => $this->campaigns_id ? urlencode($this->campaigns_id) : '',
		'{country}'        => $this->country ? urlencode($this->country) : '',
		// '{creative}'       => $this->creative!='-' ? urlencode($this->creative) : '',
		// '{device}'         => $this->device!=' ' ? urlencode($this->device."-".$this->device_model) : '',
		'{device_id}'      => $this->device_id ? urlencode($this->device_id) : '',
		'{gaid}'           => $this->gaid ? urlencode($this->gaid) : '',
		'{idfa}'           => $this->idfa ? urlencode($this->idfa) : '',
		'{keyword}'        => $this->keyword ? urlencode($this->keyword) : '',
		'{ktoken}'         => $this->tid_internal ? urlencode($this->tid_internal) : '',
		// '{metadata}'       => self::getClickdata($this),
		'{os}'             => $this->os!=' ' ? urlencode($this->os."-".$this->os_version) : '',
		'{placement}'      => $this->placement!='-' ? urlencode($this->placement) : '',
		'{provider_id}'    => $this->providers_id ? urlencode($this->providers_id) : '',
		'{publisher_id}'   => $this->publisher_id ? urlencode($this->publisher_id) : '',
		'{random}'         => (new DateTime)->getTimestamp(),
		'{referer_domain}' => self::getDomain($this->referer),
		// '{referer}'        => $this->referer ? urlencode($this->referer) : '',
		'{source}'         => self::getSource($this,$advertiserId=null),
		'{target}'         => $this->target!='-' ? urlencode($this->target) : '',
		'{tid}'            => $this->tid ? urlencode($this->tid) : '',
		);
}

public function haveMacro($url)
{
	preg_match('%\{[a-z \_]+\}%', $url, $match);
	return isset($match[0]) ? true : false;
}

public function replaceMacro($url)
{	
	return str_replace(array_keys(self::macros()),array_values(self::macros()),$url);
}

public static function getSource($click,$advertiserId)
{	
	$providers_id = $click->providers_id;
	$source       = $click->source;

	if ($advertiserId == 376)
	{
		if($source != "kickads")
			return $providers_id."_".$source;
		else
			return $providers_id."_kickads";
	}else{
		return $source;
	}
}

public function isInvalidSource($source)
{
	$invalid   = false;
	//Check si tiene caracteres extraños
	if(!preg_match('#'.'(^(((([a-z])|([A-Z])|([0-9])|([- !$%&*()_+|~=`{}\[\]:;<>?,.ñ\/]))))*$)'.'#',$source)){
		return true;
	}
	
	// Check si es un source vacio
	if(empty($_GET["source"])){
		return true;
	}

	//Check si tiene más de 3 números seguidos
	if(preg_match('^[0-9]{3}^',$source)){
		return true;
	}

	// Check si el source es menor de 3 caracteres
	if(strlen($source) <= 3){
		return true;
	}

	// Check si el source es mayor o igual a 30 caracteres
	if(strlen($source) >= 30){
		return true;
	}

	// Check si tiene más de 4 consonantes consecutivas
	if(preg_match('^([b-df-hj-np-tv-z]){4,}^',$source)){
		return true;
	}

	// Check si no existe en la white list
	$sourceList = new SourceList;
	if($sourceList->getBySource($source))
		return false;

	//CheckBlackList
	$blacklist = Blacklist::model()->findAll();
	foreach ($blacklist as $key => $value) {
		if (stripos($source, $value->source) === false)
		{
			$invalid = false;
		}else{
			$invalid = true;
			return $invalid;
		}
	}
	return $invalid;
}

public static function getSource($click,$advertiserId)
{	
	$providers_id = $click->providers_id;
	$source       = $click->source;

	if ($advertiserId == 376)
	{
		if($source != "kickads")
			return $providers_id."_".$source;
		else
			return $providers_id."_kickads";
	}else{
		return $source;
	}
}

public function actionIndex($id=null, $isRemanent=false, $isVectorBackButton=false)
{
	if(isset($id)){
		$cid = $id;
	}else{
		throw new CHttpException(404,'CID cannot be found.');
	}

	$ntoken       = isset($_GET['ntoken']) ? $_GET['ntoken'] : null;
	$publisher_id = isset($_GET["pub_id"]) ? $_GET["pub_id"] : null;

	// Get Models
	$campaign = ClicksLogToday::forTable('clicks_log_today')->getCampaignForTracking($cid);
	if ($campaign){
		$opportunity = ClicksLogToday::forTable('clicks_log_today')->getOpportunityForTracking($campaign->opportunities_id);
		$provider    = ClicksLogToday::forTable('clicks_log_today')->getProviderForTracking($campaign->providers_id);
		if($opportunity){
			$deal = ClicksLogToday::forTable('clicks_log_today')->getDealForTracking($opportunity->deals_id);
			if ($deal){
				$redirectURL  = $campaign->url;
				$nid          = $campaign->providers_id;
				$s2s          = $opportunity->server_to_server ? $opportunity->server_to_server : NULL;
				$pub_id       = $opportunity->placeholder_publisher ? $opportunity->placeholder_publisher : NULL;
				$source       = $opportunity->placeholder_source ? $opportunity->placeholder_source : NULL;
				$prov_id      = $opportunity->placeholder_provider_id ? $opportunity->placeholder_provider_id : NULL;
				$device_id    = $opportunity->placeholder_device_id ? $opportunity->placeholder_device_id : NULL;
				$gaid         = $opportunity->placeholder_gaid ? $opportunity->placeholder_gaid : NULL;
				$idfa         = $opportunity->placeholder_idfa ? $opportunity->placeholder_idfa : NULL;
				$advertiserId = $deal->advertisers_id;
			}else{
			throw new CHttpException(404,'Deal cannot be found.');
			}
		}else{
			throw new CHttpException(404,'Opportunity cannot be found.');
		}
	}else{
		throw new CHttpException(404,'Campaign cannot be found.');		
	}

	//PAUSAR CAMPAÑA. Refactor: automatizar con interfaz en servidor
	if(in_array($cid, array(13306,13473,13862))){
		http_response_code(400);
		echo json_encode(array('error'=>TRUE, 'message'=>'CAMPAIGN_PAUSED'));
		Yii::app()->end();
	}

	//PAUSAR Provider. Refactor: automatizar con interfaz en servidor
	if($nid == 1057){
		http_response_code(400);
		echo json_encode(array('error'=>TRUE, 'message'=>'CAMPAIGN_PAUSED'));
		Yii::app()->end();
	}

	//BLOQUEAR CLICKS DE CAMPAÑAS DE UBER SIN source 
	if($advertiserId == 376 && (!isset($_GET["source"]) || empty($_GET["source"])) ){
		http_response_code(400);
		echo json_encode(array('error'=>TRUE, 'message'=>'NOK-SOURCE'));
		Yii::app()->end();			
	}

	$model = ClicksLogToday::forTable('clicks_log_today');
	$model->campaigns_id = $cid;
	$model->providers_id = $nid;
	$model->publisher_id = $publisher_id;

	// Get custom params
	if ( $provider && $provider->has_s2s ) {
		foreach ($_GET as $key => $value) {
			$ignore_params = array(
				'g_net', 'g_key', 'g_cre', 'g_pla', 'g_mty', 'g_dev',
				'b_key', 'g_cre', 'b_mty', 'b_dev', 'b_q',
				'ntoken', 'nid', 'cid', 'ts', 'id', 'pub_id', 'source', 
				'device_id', 'gaid', 'idfa'
				);
			if ( !in_array($key, $ignore_params) ) {
				$model->custom_params != NULL ? $model->custom_params .= '&' : NULL ;
				$model->custom_params .= $key . '=' . $value;
			}
		}
	}

	// Get visitor parameters
	$model->server_ip    = isset($_SERVER["REMOTE_ADDR"]) ? $_SERVER["REMOTE_ADDR"] : null;
	$model->ip_forwarded = isset($_SERVER["HTTP_X_FORWARDED_FOR"]) ? $_SERVER["HTTP_X_FORWARDED_FOR"] : null;
	$model->user_agent   = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : null;
	$model->languaje     = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? $_SERVER['HTTP_ACCEPT_LANGUAGE'] : null;
	$model->referer      = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : null;
	$model->app          = isset($_SERVER['HTTP_X_REQUESTED_WITH']) ? $_SERVER['HTTP_X_REQUESTED_WITH'] : null;
	$model->redirect_url = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : null;
	
	// Get IP & GEO data
	$ip = isset($model->ip_forwarded) ? $model->ip_forwarded : $model->server_ip;
	if(isset($ip)){
		$binPath  = YiiBase::getPathOfAlias('application') . "/data/ip2location.BIN";
		$location = null;
		try{
			$location       = new IP2Location($binPath, IP2Location::FILE_IO);
		}  catch (Exception $e){
			$location = null;
		}

		if($location){
			try{
				$ipData = $location->lookup($ip, IP2Location::ALL);
			}  catch (Exception $e){
				$ipData['countryCode'] = null;
				$ipData['cityName'] = null;
				$ipData['mobileCarrierName'] = null;
			}
		} else {
			$ipData['countryCode'] = null;
			$ipData['cityName'] = null;
			$ipData['mobileCarrierName'] = null;
		}
		//$model->country = $ipData->countryName;
		$model->country = $ipData['countryCode'];
		$model->city    = $ipData['cityName'];
		$model->carrier = $ipData['mobileCarrierName'];
	}

	/*
	* Get Device Data
	* Inicio Componente Browser
	*/
	if(isset($model->user_agent)){
		$device = new Browser($model->user_agent);
		$model->os              = $device ? $device->getPlatform() : null;
		$model->browser         = $device ? $device->getBrowser() : null;
		$model->browser_version = $device ? $device->getVersion() : null;
	}
	/*Fin componente Browser*/

	/*Inicio procesamiento source_list y device_id*/
	switch ($advertiserId) {
		case 376:
			if ($campaign->opportunities_id == 3042){
				$criteriaAC        = new CDbCriteria;
				$criteriaAC->order = 'RAND()';
				$randomSource      = Autocomplete::model()->find( $criteriaAC )->source;
				$model->source     = $randomSource;
			}elseif($model->providers_id == 1095){
				if ( isset($_GET["source"]) && !$model->isInvalidSource($_GET["source"]) ){
					$model->source = $_GET["source"];
				}else{
					$criteriaRS        = new CDbCriteria;
					$criteriaRS->compare('os','Android');
					$criteriaRS->order = 'RAND()';
					$randomSource      = SourceList::model()->find( $criteriaRS )->source;
					$model->source     = $randomSource;
				}
			}else{
				if ($model->os == "Android"){
					$criteriaRS        = new CDbCriteria;
					$criteriaRS->compare('os','Android');
					$criteriaRS->order = 'RAND()';
					$randomSource      = SourceList::model()->find( $criteriaRS )->source;
					$model->source     = $randomSource;
				}else{
					$criteriaRS        = new CDbCriteria;
					$criteriaRS->compare('os','iOS');
					$criteriaRS->order = 'RAND()';
					$randomSource      = SourceList::model()->find( $criteriaRS )->source;
					$model->source     = $randomSource;			
				}
			}

			$model->device_id = isset($_GET['device_id']) ? $_GET['device_id'] : null;
			$model->gaid      = isset($_GET['gaid']) ? $_GET['gaid'] : null;
			$model->idfa      = isset($_GET['idfa']) ? $_GET['idfa'] : null;
			break;

		case 484:
			$model->source    = isset($_GET['source']) ? $_GET['source'] : null;
			$model->gaid      = isset($_GET['gaid']) ? $_GET['gaid'] : null;
			$model->idfa      = isset($_GET['idfa']) ? $_GET['idfa'] : null;
			/*Sentencia para 100 autocomplete*/
			// if ($model->os == "Android"){
			// 	$criteriaRS        = new CDbCriteria;
			// 	$criteriaRS->compare('os','Android');
			// 	$criteriaRS->order = 'RAND()';
			// 	$randomSource      = SourceList::model()->find( $criteriaRS )->source;
			// 	$model->source     = $randomSource;
			// }else{
			// 	$criteriaRS        = new CDbCriteria;
			// 	$criteriaRS->compare('os','iOS');
			// 	$criteriaRS->order = 'RAND()';
			// 	$randomSource      = SourceList::model()->find( $criteriaRS )->source;
			// 	$model->source     = $randomSource;			
			// }
			break;

		case 491:
				if ( isset($_GET["source"]) && !$model->isInvalidSource($_GET["source"]) ){
					$model->source = $_GET["source"];
				}else{
					$criteriaRS        = new CDbCriteria;
					$criteriaRS->order = 'RAND()';
					$randomSource      = SourceList::model()->find( $criteriaRS )->source;
					$model->source     = $randomSource;
				}

				if ( isset($_GET["gaid"]) && ($_GET["gaid"] != '') )
				{
					$model->gaid = $_GET["gaid"];
				}else{
					http_response_code(400);
					echo json_encode(array('error'=>TRUE, 'message'=>'NOK-GAID'));
					error_log('provider:'.$provClick->name.' - campaign:'.$model->campaigns->id.': NOK-GAID');
					Yii::app()->end();
				}
			break;
		
		default:
			$model->source    = isset($_GET['source']) ? $_GET['source'] : null;
			$model->device_id = isset($_GET['device_id']) ? $_GET['device_id'] : null;
			$model->gaid      = isset($_GET['gaid']) ? $_GET['gaid'] : null;
			$model->idfa      = isset($_GET['idfa']) ? $_GET['idfa'] : null;
			break;
	}
	/*Fin procesamiento source_list y device_id*/

	try {
		$model->save();	
		$ktoken              = md5($model->id);	
		$model->tid          = $ntoken;
		$model->tid_internal = $ktoken;			
		$model->save();

		if($s2s){
			if( strpos($redirectURL, "?") ){
				$redirectURL.= "&";
			} else {
				$redirectURL.= "?";
			}
			$redirectURL.= $s2s."=".$ktoken;
		}

		if($pub_id){
			if( strpos($redirectURL, "?") ){
				$redirectURL.= "&";
			} else {
				$redirectURL.= "?";
			}
			$redirectURL.= $pub_id."=".$model->publisher_id;
		}

		if($source)
		{
			if( strpos($redirectURL, "?") ){
				$redirectURL.= "&";
			} else {
				$redirectURL.= "?";
			}
			$redirectURL.= $source."=".$model->getSource($model,$advertiserId);
		}

		if($device_id)
		{
			if( strpos($redirectURL, "?") ){
				$redirectURL.= "&";
			} else {
				$redirectURL.= "?";
			}
			$redirectURL.= $device_id."=".$model->device_id;
		}

		if($prov_id)
		{
			if( strpos($redirectURL, "?") ){
				$redirectURL.= "&";
			} else {
				$redirectURL.= "?";
			}
			$redirectURL.= $prov_id."=".$model->providers_id;
		}

		if($gaid)
		{
			if( strpos($redirectURL, "?") ){
				$redirectURL.= "&";
			} else {
				$redirectURL.= "?";
			}
			$redirectURL.= $gaid."=".$model->gaid;
		}

		if($idfa)
		{
			if( strpos($redirectURL, "?") ){
				$redirectURL.= "&";
			} else {
				$redirectURL.= "?";
			}
			$redirectURL.= $idfa."=".$model->idfa;
		}

		//Enviar macros
		if($model->haveMacro($redirectURL))
			$redirectURL = $model->replaceMacro($redirectURL);
		
		/* RedirectURL mediante fraudscore o header location by kickads*/
		if($advertiserId == 376 && $campaign->opportunities_id != 2761){
			$fraud_key          = "b0829aaa9d546db5f02545424b82d7ec";
			$fraud_ip           = isset($model->server_ip) ? $model->server_ip : $model->ip_forwarded;
			$fraud_ua           = urlencode($model->user_agent);
			$fraud_time         = time();
			$fraud_at           = date("Y-m-d H:i:s", $fraud_time);
			$fraud_affiliate_id = $model->providers_id;
			$fraud_offer_id     = $campaign->opportunities_id;
			$fraud_offer_name   = $model->campaigns_id;
			$fraud_source       = $model->source;
			$fraud_publisher_id = $model->publisher_id;
			$target_url			= urlencode($redirectURL);
			$fallback_url		= urlencode("https://sidekickads.com/clicksLog/clickError");
			//URL Fraudscore
			$fraud_url = "https://check.fraudscore.mobi/?event_type=click"."&target_url=".$target_url."&fallback_url=".$fallback_url."&ip=".$fraud_ip."&ua=".$fraud_ua."&key=".$fraud_key."&at=".$fraud_at."&advertiser_id=".$advertiserId."&affiliate_id=".$fraud_affiliate_id."&offer_id=".$fraud_offer_id."&offer_name=".$fraud_offer_name."&session_time=".$fraud_at."&source=".$fraud_source."&aff_sub1=".$fraud_publisher_id;
			$fraud_url = preg_replace('/\s\s+/', '', $fraud_url);
			try{
				header("Location: ".$fraud_url);
			} catch(Exception $e) {
				Yii::log( "clickId: $model->id, url: $fraud_url message: ". $e->getMessage(), 'error', 'systag.model.clicksLog.fraudscore');
			}
		}else{
			try {
				header("Location: ".$redirectURL);
			} catch(Exception $e) {
				Yii::log( "clickId: $model->id, url: $redirectURL message: ". $e->getMessage(), 'error', 'system.model.clicksLog');
			}
		}
		/* Fin Redirect */
	} catch (Exception $e) {
		Yii::log( "clickId: $model->id, url: $redirectURL message: ". $e->getMessage(), 'error', 'system.model.clicksLog.save');
	}
}