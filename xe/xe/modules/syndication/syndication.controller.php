<?php
/* Copyright (C) NAVER <http://www.navercorp.com> */

/**
 * @class  syndicationController
 * @author NAVER (developers@xpressengine.com)
 * @brief syndication module's Controller class
 **/

class syndicationController extends syndication
{
	var $ping_message = '';

	function triggerInsertDocument(&$obj) {
		if($obj->module_srl < 1) return $this->makeObject();
		if($obj->status !== 'PUBLIC') return $this->makeObject();

		$oSyndicationModel = getModel('syndication');
		$oModuleModel = getModel('module');

		if($oSyndicationModel->isExceptedModules($obj->module_srl)) return $this->makeObject();

		$config = $oModuleModel->getModuleConfig('syndication');

		if($config->syndication_use!='Y') return $this->makeObject();

		$target_id = sprintf('%s-%s', $obj->module_srl, $obj->document_srl);
		$id = $oSyndicationModel->getID('article', $target_id);
		$this->ping($id, 'article');

		return $this->makeObject();
	}

	function triggerUpdateDocument(&$obj) {
		if($obj->module_srl < 1) return $this->makeObject();

		$oSyndicationModel = getModel('syndication');
		$oModuleModel = getModel('module');

		if($oSyndicationModel->isExceptedModules($obj->module_srl)) return $this->makeObject();

		$config = $oModuleModel->getModuleConfig('syndication');

		if($config->syndication_use!='Y') return $this->makeObject();

		$target_id = sprintf('%s-%s', $obj->module_srl, $obj->document_srl);
		$id = $oSyndicationModel->getID('article', $target_id);

		// PUBLIC 외 삭제
		if($obj->status === 'PUBLIC')
		{
			$this->ping($id, 'article');
		}
		else
		{
			$this->ping($id, 'deleted');
		}

		return $this->makeObject();
	}

	function triggerDeleteDocument(&$obj) {
		if($obj->module_srl < 1) return $this->makeObject();

		$oModuleModel = getModel('module');
		$oSyndicationModel = getModel('syndication');

		$config = $oModuleModel->getModuleConfig('syndication');
		if($config->syndication_use != 'Y') return $this->makeObject();
		if($oSyndicationModel->isExceptedModules($obj->module_srl)) return $this->makeObject();

		$this->insertLog($obj->module_srl, $obj->document_srl, $obj->title, $obj->content);

		$target_id = sprintf('%s-%s', $obj->module_srl, $obj->document_srl);
		$id = $oSyndicationModel->getID('article', $target_id);
		$this->ping($id, 'deleted');

		return $this->makeObject();
	}

	// @DEPRECATED
	function triggerDeleteModule(&$obj) {
		return $this->makeObject();
	}

	function triggerMoveDocumentModule(&$obj)
	{
		if($obj->module_srl < 1) return $this->makeObject();

		$oSyndicationModel = getModel('syndication');
		$oModuleModel = getModel('module');

		if($oSyndicationModel->isExceptedModules($obj->module_srl)) return $this->makeObject();

		$config = $oModuleModel->getModuleConfig('syndication');

		if($config->syndication_use!='Y') return $this->makeObject();

		$arr_document_srl = explode(',', $obj->document_srls);
		if(!$arr_document_srl) return $this->makeObject();

		foreach($arr_document_srl as $document_srl)
		{
			// 기존 문서 삭제
			$source_module_srl = $obj->source_module_srl[$document_srl];
			if(!!$source_module_srl)
			{
				$target_id = sprintf('%s-%s', $source_module_srl, $document_srl);
				$id = $oSyndicationModel->getID('article', $target_id);
				$this->ping($id, 'deleted');
			}

			// 옮겨진 문서 추가
			$target_id = sprintf('%s-%s', $obj->module_srl, $document_srl);
			$id = $oSyndicationModel->getID('article', $target_id);
			$this->ping($id, 'article');
		}

		return $this->makeObject();
	}

	function triggerMoveDocumentToTrash(&$obj) {
		$oSyndicationModel = getModel('syndication');
		$oModuleModel = getModel('module');

		if($oSyndicationModel->isExceptedModules($obj->module_srl)) return $this->makeObject();

		$config = $oModuleModel->getModuleConfig('syndication');

		if($config->syndication_use!='Y') return $this->makeObject();

		$this->insertLog($obj->module_srl, $obj->document_srl, '', '');

		$target_id = sprintf('%s-%s', $obj->module_srl, $obj->document_srl);
		$id = $oSyndicationModel->getID('article', $target_id);
		$this->ping($id, 'deleted');

		return $this->makeObject();
	}

	// @deprecated
	function triggerRestoreTrash(&$obj) {
		// 중복 전송으로 인해 중단
		return $this->makeObject();
	}

	function insertLog($module_srl, $document_srl, $title = null, $summary = null)
	{
		$args = new stdClass;
		$args->module_srl = $module_srl;
		$args->document_srl = $document_srl;
		$args->title = $title;
		$args->summary = $summary;
		$output = executeQuery('syndication.insertLog', $args);
	}

	function deleteLog($module_srl, $document_srl)
	{
		$args = new stdClass;
		$args->module_srl = $module_srl;
		$args->document_srl = $document_srl;
		$output = executeQuery('syndication.deleteLog', $args);
	}

	function ping($id, $type, $page=1) {
		$this->ping_message = '';
		$oSyndicationModel = getModel('syndication');

		$oModuleModel = getModel('module');
		$config = $oModuleModel->getModuleConfig('syndication');

		if(!$config->syndication_token)
		{
			$this->ping_message = 'Syndication Token empty';
			$oSyndicationModel->setResentPingLog($this->ping_message);
			return false;
		}

		if(!$this->checkOpenSSLSupport())
		{
			$lang = Context::get('lang');
			$this->ping_message = $lang->msg_need_openssl_support;
			$oSyndicationModel->setResentPingLog($this->ping_message);
			return false;
		}

		if(substr($config->site_url,-1)!='/')
		{
			$config->site_url .= '/';
		}

		$ping_url = 'https://apis.naver.com/crawl/nsyndi/v2';
		$ping_header = array();
		$ping_header['Host'] = 'apis.naver.com';
		$ping_header['Pragma'] = 'no-cache';
		$ping_header['Accept'] = '*/*';
		$ping_header['Authorization'] = sprintf("Bearer %s", $config->syndication_token);

		$request_config = array();
		$request_config['ssl_verify_peer'] = false;

		$ping_body = getNotEncodedFullUrl('', 'module', 'syndication', 'act', 'getSyndicationList', 'id', $id, 'type', $type, 'page', $page, 'syndication_password', $config->syndication_password);

		$buff = FileHandler::getRemoteResource($ping_url, null, 10, 'POST', 'application/x-www-form-urlencoded', $ping_header, array(), array('ping_url'=>$ping_body), $request_config);

		$xml = new XmlParser();
		$xmlDoc= $xml->parse($buff);

		if($xmlDoc->result->error_code->body != '000')
		{
			if(!$buff)
			{
				$this->ping_message = 'Socket connection error. Check your Server Environment.';
			}
			else
			{
				$this->ping_message = $xmlDoc->result->message->body;
			}

			$oSyndicationModel->setResentPingLog($this->ping_message);
			return false;
		}
		return true;
	}
}
