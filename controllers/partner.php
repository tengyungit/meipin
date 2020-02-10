<?php

/**
 * @class Partner
 * @brief 合作方模块
 * @note  后台
 */
class Partner extends IController implements adminAuthorization
{
	public $checkRight  = 'all';
	public $layout = 'admin';
	public $data = array();

	function init()
	{
	}

	//转让授权
	function is_sale(){
		$id = IFilter::act(IReq::get('id'), 'int');
		if (!$id) {
			$this->user_list();
			$msg = "参数错误";
			Util::showMessage($msg);
		}

		$tb_partner = new IModel('partner_account');
		$where = "id=" . $id;
		$info = $tb_partner->getObj($where);
		if(empty($info)){
			$this->user_list();
			$msg = "没有找到用户资金信息";
			Util::showMessage($msg);
		}

		$partner = array(
			'is_sale' => $info['is_sale']==0?1:0,
		);
		$tb_partner->setData($partner);
	
		$tb_partner->update($where);
		$this->user_list();
	}

	//权益金类型配置新增
	function config_add()
	{
		$this->redirect('config_add');
	}

	//权益金类型配置修改
	function config_edit()
	{
		$id  = IFilter::act(IReq::get('id'), 'int');
		$config_data = array();

		//编辑平台配置 读取平台配置 
		if ($id) {
			$obj_config = new IModel('partner_account_config');
			$partner_info = $obj_config->getObj('id=' . $id);
			if ($partner_info) {
				$config_data = $partner_info;
			} else {
				$this->redirect('config_list');
				Util::showMessage("没有找到相关配置信息");
				return;
			}
		}
		$this->setRenderData(array('config' => $config_data));
		$this->redirect('config_edit');
	}

	//保存权益金配置
	function config_save()
	{
		$id = IFilter::act(IReq::get('id'), 'int');
		$name = IFilter::act(IReq::get('name'));
		$code = IFilter::act(IReq::get('code'));
		$percent = IFilter::act(IReq::get('percent'));

		$tb_config = new IModel('partner_account_config');


		if ($id) {
			$config = array(
				'name' => $name,
				'code' => $code,
				'percent' => $percent,
			);

			//判断重复
			$is_exisits = $tb_config->getObj('id !=' . $id . ' and code="' . $code . '"');
			if (!empty($is_exisits)) {
				$this->redirect('/partner/config_edit/id/' . $id . '/_msg/配置标识重复');
				return;
			}

			$tb_config->setData($config);
			//保存修改权益金配置
			$where = "id=" . $id;
			$tb_config->update($where);
		} else {
			$config = array(
				'name' => $name,
				'code' => $code,
				'percent' => $percent,
			);

			$is_exisits = $tb_config->getObj('code="' . $code. '"');
			if (!empty($is_exisits)) {
				$this->redirect("/partner/config_add/_msg/配置标识重复");
				return;
			}

			$tb_config->setData($config);
			$tb_config->add();
		}
		$this->config_list();
	}

	//权益金类型配置列表
	function config_list()
	{
		$queryObj = Api::run('getPartnerConfig');
		$this->setRenderData(array('queryObj' => $queryObj));
		$this->redirect('config_list');
	}

	/**
	 * @brief 合作方用户列表
	 */
	function user_list()
	{
		$queryObj = Api::run('getAllUserList');
		$res = Api::run('getAllUserBalance');
		$this->setRenderData(array('queryObj' => $queryObj, 'balance_all' => $res['balance'], 'recharge_all' => $res['recharge']));
		$this->redirect('user_list');
	}

	/**
	 * @brief 合作方用户资金列表
	 */
	function account_log()
	{
		$queryObj = Api::run('getAccoutLog');
		$count = Api::run('getAccoutCount');
		$this->setRenderData(array('queryObj' => $queryObj, 'count' => $count));
		$this->redirect('account_log');
	}


	/**
	 * @brief 添加合作方平台
	 */
	function partner_add()
	{
		$this->redirect('partner_add');
	}
	/**
	 * @brief 修改合作方平台
	 */
	function partner_edit()
	{
		$id  = IFilter::act(IReq::get('id'), 'int');
		$partnerData = array();

		//编辑平台配置 读取平台配置 
		if ($id) {
			$obj_partner = new IModel('partner');
			$partner_info = $obj_partner->getObj('id=' . $id);
			if ($partner_info) {
				$partner_info['account_config'] = explode(",",$partner_info['account_config']);
				$partnerData = $partner_info;
			} else {
				$this->redirect('partner_list');
				Util::showMessage("没有找到相关平台信息");
				return;
			}
		}
		$this->setRenderData(array('partner' => $partnerData));
		$this->redirect('partner_edit');
	}

	/**
	 * @brief 保存品牌
	 */
	function partner_save()
	{
		$id = IFilter::act(IReq::get('id'), 'int');
		$partner_name = IFilter::act(IReq::get('partner_name'));
		$contact = IFilter::act(IReq::get('contact'));
		$phone = IFilter::act(IReq::get('phone'));
		$status = IFilter::act(IReq::get('status'), 'int');
		$account_config = IFilter::act(IReq::get('account_config'));
		if(!empty($account_config)){
			$account_config = implode("," , $account_config);
		}


		$tb_partner = new IModel('partner');


		if ($id) {
			$appsecret = IFilter::act(IReq::get('appsecret'));

			$partner = array(
				'partner_name' => $partner_name,
				'contact' => $contact,
				'phone' => $phone,
				'status' => $status,
				'appsecret' => $appsecret,
				'update_time' => time(),
				'account_config'=>$account_config,
			);
			//判断重复
			$is_exisits = $tb_partner->getObj('id !=' . $id . ' and is_del=0 and partner_name="' . $partner_name . '"');
			if (!empty($is_exisits)) {
				$this->redirect('/partner/partner_edit/id/' . $id . '/_msg/平台名称重复');
				return;
			}

			$tb_partner->setData($partner);
			//保存修改平台配置
			$where = "id=" . $id;
			$tb_partner->update($where);
		} else {
			$partner = array(
				'partner_name' => $partner_name,
				'contact' => $contact,
				'phone' => $phone,
				'status' => $status,
				'add_time' => time(),
				'update_time' => time(),
				'account_config'=>$account_config,
			);

			$is_exisits = $tb_partner->getObj('partner_name="' . $partner_name . '" and is_del=0');
			if (!empty($is_exisits)) {
				$this->redirect("/partner/partner_add/_msg/平台名称重复");
				return;
			}

			//添加新平台配置 	//自动生成app_id app_secret
			$app = $this->create_app($partner_name);
			$partner['appid'] = $app['appid'];
			$partner['appsecret'] = $app['appsecret'];
			//print_r($partner);exit;
			$tb_partner->setData($partner);
			$tb_partner->add();
		}
		$this->partner_list();
	}

	/**
	 * @brief 删除平台配置
	 */
	function partner_del()
	{
		$id = IFilter::act(IReq::get('id'), 'int');
		if ($id) {
			$tb_partner = new IModel('partner');
			$partner = array(
				'is_del' => '1',
				'update_time' => time(),
			);
			$tb_partner->setData($partner);
			//保存修改平台配置
			$where = "id=" . $id;
			if ($tb_partner->update($where)) {
				$this->partner_list();
			} else {
				$this->partner_list();
				$msg = "没有找到平台信息";
				Util::showMessage($msg);
			}
		} else {
			$this->partner_list();
			$msg = "没有找到平台信息";
			Util::showMessage($msg);
		}
	}


	/**
	 * @brief 合作方列表
	 */
	function partner_list()
	{
		$this->redirect('partner_list');
	}

	//生成app_id和app_secret
	private function create_app($namespace = '')
	{
		static $guid = '';
		$uid = uniqid("", true);
		$data = $namespace;
		$data .= $_SERVER['REQUEST_TIME'];
		$data .= $_SERVER['HTTP_USER_AGENT'];
		$data .= $_SERVER['SERVER_ADDR'];
		$data .= $_SERVER['SERVER_PORT'];
		$data .= $_SERVER['REMOTE_ADDR'];
		$data .= $_SERVER['REMOTE_PORT'];
		$hash = hash('ripemd128', $uid . $guid . md5($data));
		$guid = substr($hash,  0,  32);
		$app['appid'] =  "mp" . substr(md5($hash),  0,  14);
		$app['appsecret'] =  $guid;
		return $app;
	}
}
