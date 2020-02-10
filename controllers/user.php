<?php

/**
 * @class User
 * @brief 合作平台接口
 * @note  前台
 */
class User extends IController
{
	/**
	 *	显示错误代码
	 */
	private function return_msg($code, $info = '', $data = array())
	{
		$error_arr = array(
			'deal_succ' => array('code' => '1', 'msg' => '操作成功', 'info' => $info, 'data' => $data),
			'server_error' => array('code' => "001001", 'msg' => '服务端错误', 'info' => $info),
			'data_error' => array('code' => "001002", 'msg' => '数据错误', 'info' => $info),
		);
		echo JSON::encode($error_arr[$code]);
		exit;
	}

	public $layout = '';

	public function init()
	{
	}

	//开户
	function open_account()
	{
		$data = $this->checkApp();
		$mobile = $data['mobile'];
		$appid = $data['appid'];

		//注册
		$userObj = new IModel('user');

		//检测用户是否在商城注册过
		$userRow = $userObj->getObj('username = "' . $mobile . '"');
		if (empty($userRow)) {
			//如果开户前用户已经在平台注册过 而且 用户名不为手机号 就替换
			$memberObj = new IModel('member');
			$is_member = $memberObj->getObj('mobile = "' . $mobile . '"');

			//插入user表
			$userArray = array(
				'username' => $mobile,
				'password' => md5(substr($mobile, -6)),
			);

			$userObj->setData($userArray);
			if (!empty($is_member)) {
				$where = "id=" . $is_member['user_id'];
				$userObj->update($where);
				$user_id = $is_member['user_id'];
			} else {
				$user_id = $userObj->add();
			}

			if (!$user_id) {
				$userObj->rollback();
				$this->return_msg("data_error", '用户创建失败');
			}
			$userArray['id'] = $user_id;
			$userArray['head_ico'] = "";

			//插入member表
			$memberArray = array(
				'user_id' => $user_id,
				'time'    => ITime::getDateTime(),
				'status'  => 1,
				'mobile'  => $mobile,
			);
			$memberObj->setData($memberArray);
			if (!empty($is_member)) {
				$where = "user_id=" . $is_member['user_id'];
				$memberObj->update($where);
			} else {
				$memberObj->add();
			}

			//会员组更新
			plugin::trigger("expUpdate", $user_id);
		} else {
			$user_id = $userRow['id'];
		}

		$token = $this->encodePass(strval($user_id), 'meipin', "encode");
		//设置token过期时间
		$key = 'token:' . md5($user_id);
		$redis = new IRedisCache();
		$expire = time() + 60 * 60 * 24 * 7;
		$redis->set($key, $token, $expire);
		$this->return_msg("deal_succ", '开户成功', $token);
	}


	//token过期重新获取token
	function get_token()
	{
		$data = $this->checkApp();
		//检测账号密码
		$userObj = new IModel('user');
		$userRow = $userObj->getObj('username = "' . $data['mobile'] . '"');
		if (empty($userRow)) {
			$this->return_msg("data_error", '账号不存在');
		}

		$user_id = $userRow['id'];

		$token = $this->encodePass(strval($user_id), 'meipin', "encode");
		//设置token过期时间
		$key = 'token:' . md5($user_id);
		$redis = new IRedisCache();
		$expire = time() + 60 * 60 * 24 * 7;
		$redis->set($key, $token, $expire);
		$this->return_msg("deal_succ", '开户成功', $token);
	}

	//根据token登录
	function login()
	{
		$token  = IFilter::act(IReq::get('token', 'get'));

		if (empty($token)) {
			$this->return_msg("data_error", '参数错误');
		}

		//解密
		$user_id = $this->encodePass($token, 'meipin', "decode");
		if (empty($user_id)) {
			$this->return_msg("data_error", 'token错误');
		}

		//检测redis
		$key = 'token:' . md5($user_id);
		$redis = new IRedisCache();
		$token_value = $redis->get($key);

		if (empty($token_value) || $token != $token_value) {
			$this->return_msg("data_error", '登陆已失效,请重新登陆');
		}

		//登陆
		$userObj = new IModel('user');
		$userRow = $userObj->getObj('id = "' . $user_id . '"');
		if (!$userRow) {
			$this->return_msg("data_error", '账号不存在');
		}

		$userRow['last_login'] = ITime::getDateTime();

		//保存页面cookies
		plugin::trigger("userLoginCallback", $userRow);

		$this->redirect("/site/index");
	}

	//兑换充值
	function recharge()
	{
		$data = $this->checkApp();

		$money = $data['money'];
		if ($money <= 0   || !is_numeric($money)) {
			$this->return_msg("data_error", '金额错误');
		}

		$account_type = $data['account_type'];
		if(!in_array($account_type,$data['account_config'])){
			$this->return_msg("data_error", '权益金类型未授权');
		}

		$userObj = new IModel('user');
		//检测用户
		$userRow = $userObj->getObj('username = "' . $data['mobile'] . '"');
		if(empty($userRow)){
			$this->return_msg("data_error", '用户不存在');
		}

		//检测资金账号
		$tb_partner_account = new IModel('partner_account');
		$accountRow = $tb_partner_account->getObj('user_id= "'.$userRow['id'].'" and appid = "' . $data['appid'] . '" and account_type="' . $account_type . '"');
		if (empty($accountRow)) {
			//账号不存在 新增资金账户
			$accuntArray = array(
				'user_id' => $userRow['id'],
				'appid' => $data['appid'],
				'balance' => 0,
				'addtime'    => ITime::getDateTime(),
				'account_type' =>$account_type,
			);
			$tb_partner_account->setData($accuntArray);
			$account_id = $tb_partner_account->add();
			if (!$account_id) {
				$tb_partner_account->rollback();
				$this->return_msg("data_error", '资金账户创建失败');
			}
		}

		$query = new IQuery("user as a");
		$query->join   = 'left join partner_account as b on a.id = b.user_id left join partner as c on b.appid=c.appid';
		$query->where  = 'a.username="' . $data['mobile'] . '" and b.appid="' . $data['appid'] . '" and b.account_type="' . $account_type . '"';
		$query->fields = 'b.recharge_total,b.balance,b.user_id,a.username,b.appid,c.company,b.account_type';
		$array_user = $query->find();

		$user_info = $array_user[0];
		$amount = $user_info['balance'] + $money;
		$recharge_total = $user_info['recharge_total'] + $money;

		//资金增加
		$tb_partner_account->setData(array("balance" => $amount, 'recharge_total' => $recharge_total));
		$flag = $tb_partner_account->update("user_id = " . $user_info['user_id'] . " and appid='" . $data['appid'] . "' and account_type='".$user_info['account_type']."'" );
		if (!$flag) {
			$tb_partner_account->rollback();
			$this->return_msg("data_error", '兑换失败');
		}

		$tb_account_log = new IModel("account_log");
		$insertData = array(
			'admin_id'  => 0,
			'user_id'   => $user_info['user_id'],
			'event'     => '7',
			'note'      => $user_info['company'] . '兑换充值，金额：' . $money . '元',
			'amount'    => $money,
			'amount_log' => $amount,
			'type'      => '0',
			'time'      => ITime::getDateTime(),
			'appid' 	=>  $user_info['appid'],
			'account_type' => $account_type,
		);
		$tb_account_log->setData($insertData);
		$flag = $tb_account_log->add();
		if (!$flag) {
			$tb_account_log->rollback();
			$this->return_msg("data_error", '兑换失败');
		}

		$tb_account_log->commit();

		//发送短信
		$msg = "您好，由深圳前途慕尚资产管理有限公司为您充值的金桥梁权益金:" . $money . "元已到账，登陆账号" . $user_info['username'] . ",密码为手机尾号后六位,祝您购物愉快！";
		// Hsms::send($data['mobile'],$msg,0);


		$user_id = $user_info['user_id'];

		//积分
		$pointConfig = array(
			'user_id' => $user_id,
			'point'   => $money,
			'log'     => '成功兑换充值：' . $money . '元,奖励积分' . $money,
		);
		$pointObj = new Point();
		$pointObj->update($pointConfig);

		$token = $this->encodePass(strval($user_id), 'meipin', "encode");
		//设置token过期时间
		$key = 'token:' . md5($user_id);
		$redis = new IRedisCache();
		$expire = time() + 60 * 60 * 24 * 7;
		$redis->set($key, $token, $expire);
		$this->return_msg("deal_succ", '兑换成功', $token);
	}

	//兑换记录
	function recharge_log()
	{
		$data = $this->checkApp();

		$query = new IQuery("account_log as a");
		$query->join   = 'left join user as b on a.user_id=b.id';
		$query->where  = 'a.event=7 and b.username="' . $data['mobile'] . '" and a.appid="' . $data['appid'] . '"';
		$query->fields = 'a.amount,a.time,b.username,account_type';
		$array_log = $query->find();
		if (empty($array_log)) {
			$this->return_msg("data_error", '没有记录');
		}
		$this->return_msg("deal_succ", '查询成功', $array_log);
	}

	//消费记录
	function order_log()
	{
		$data = $this->checkApp();

		$query = new IQuery("order as a");
		$query->join   = 'left join order_goods as b on a.id=b.order_id left join partner_account as c on a.user_id=c.user_id left join user as d on a.user_id=d.id';
		$query->where  = 'a.status in (2,5) and d.username="' . $data['mobile'] . '" and a.appid="' . $data['appid'] . '"';
		$query->fields = 'a.real_freight,a.order_no,b.goods_array,b.goods_nums,a.real_amount,use_partner';
		$array_log = $query->find();
		if (empty($array_log)) {
			$this->return_msg("data_error", '没有记录');
		}


		$data = array();
		foreach ($array_log as $key => $value) {
			$data[$value['order_no']]['order_no'] = $value['order_no'];
			$data[$value['order_no']]['money'] = $value['real_amount'];
			$data[$value['order_no']]['use_recharge'] = $value['use_partner'];
			$data[$value['order_no']]['freight'] = $value['real_freight'];
			$temps = json_decode($value['goods_array'], true);
			$goods['name'] = $temps['name'];
			$goods['num'] = $value['goods_nums'];
			if (!empty($temps)) {
				$data[$value['order_no']]['goods'][] = $goods;
			}
		}

		$res = array();
		if (!empty($data)) {
			foreach ($data as $key => $value) {
				$res[] = $value;
			}
		}


		$this->return_msg("deal_succ", '查询成功', $res);
	}

	private function checkApp()
	{
		$data = file_get_contents("php://input");
		$data = json_decode($data, true);

		$mobile = isset($data['mobile']) ? $data['mobile'] : '';
		$appid = isset($data['appid']) ? $data['appid'] : '';
		$appsecret = isset($data['appsecret']) ? $data['appsecret'] : '';
		$sign = isset($data['sign']) ? $data['sign'] : '';
		$timestamp = isset($data['timestamp']) ? $data['timestamp'] : '';
		$money = isset($data['money']) ? $data['money'] : '';
		$account_type = isset($data['account_type']) ? $data['account_type'] : '';

		if (empty($mobile)) {
			$this->return_msg("data_error", 'mobile不能为空');
		}

		if (empty($appid)) {
			$this->return_msg("data_error", 'appid不能为空');
		}

		if (empty($appsecret)) {
			$this->return_msg("data_error", 'appsecret不能为空');
		}

		if (empty($sign)) {
			$this->return_msg("data_error", 'sign不能为空');
		}

		if (empty($timestamp)) {
			$this->return_msg("data_error", 'timestamp不能为空');
		}

		//检测手机号
		if (IValidate::mobi($mobile) == false) {
			$this->return_msg("data_error", '手机号格式不正确');
		}

		//验签
		$sign_array = array(
			'mobile' => $mobile,
			'appid' => $appid,
			'appsecret' => $appsecret,
			'sign' => $sign,
			'timestamp' => $timestamp,
		);


		if (isset($money) && !empty($money)) {
			$sign_array['money'] = $money;
		}

		if (isset($account_type) && !empty($account_type)) {
			$sign_array['account_type'] = $account_type;
		}

		$is_sign = $this->verifySign($sign_array);
		if (!$is_sign) {
			$this->return_msg("data_error", '验签失败');
		}

		//检测APPid和appsecret
		$partnerObj = new IModel('partner');
		$partner = $partnerObj->getObj('appid = "' . $appid . '" and appsecret="' . $appsecret . '" and status=1');
		if (!$partner) {
			$this->return_msg("data_error", '平台账号错误');
		}

		$sign_array['account_config'] = explode(",",$partner['account_config']);

		return $sign_array;
	}

	/**************验签函数******************/
	//验证交互数字签名是否正确
	private function verifySign($data)
	{
		$check_pub = $this->checkPubData($data);
		if (!$check_pub) {
			return false;
		}

		//验签
		$sign =  $data['sign'];
		unset($data['sign']);
		$signature = $this->CreateSignature($data);
		if ($signature != $sign) {
			return false;
		}
		return true;
	}

	private function checkPubData($data)
	{
		if (!isset($data['appid']) || empty($data['appid'])) {
			return false;
		}

		if (!isset($data['appsecret']) || empty($data['appsecret'])) {
			return false;
		}

		if (!isset($data['timestamp']) || empty($data['timestamp'])) {
			return false;
		}

		if (!isset($data['sign']) || empty($data['sign'])) {
			return false;
		}

		return true;
	}

	//生成数字签名
	private  function CreateSignature($param)
	{
		if (empty($param)  && !is_array($param)) {
			return false;
		}
		$param = $this->argSort($param);
		//字符串返回
		$arg  = '';

		foreach ($param as $key => $val) {
			$arg .= $key . "=" . $val . "&";
		}

		$arg = trim($arg, "&");
		
		$signature = md5($arg);
		return $signature;
	}

	//字符串排序
	private  function argSort($para)
	{
		if (empty($para) || !is_array($para)) {
			return   false;
		}
		ksort($para);
		reset($para);
		return  $para;
	}

	//token加密
	private function encodePass($tex, $key, $type = "encode")
	{
		$chrArr = array(
			'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm', 'n', 'o', 'p', 'q', 'r', 's', 't', 'u', 'v', 'w', 'x', 'y', 'z',
			'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z',
			'0', '1', '2', '3', '4', '5', '6', '7', '8', '9'
		);
		if ($type == "decode") {
			if (strlen($tex) < 14) return false;
			$verity_str = substr($tex, 0, 8);
			$tex = substr($tex, 8);
			if ($verity_str != substr(md5($tex), 0, 8)) {
				//完整性验证失败
				return false;
			}
		}
		$key_b = $type == "decode" ? substr($tex, 0, 6) : $chrArr[rand() % 62] . $chrArr[rand() % 62] . $chrArr[rand() % 62] . $chrArr[rand() % 62] . $chrArr[rand() % 62] . $chrArr[rand() % 62];
		$rand_key = $key_b . $key;
		$rand_key = md5($rand_key);
		$tex = $type == "decode" ? base64_decode(substr($tex, 6)) : $tex;
		$texlen = strlen($tex);
		$reslutstr = "";
		for ($i = 0; $i < $texlen; $i++) {
			$reslutstr .= $tex{
				$i} ^ $rand_key{
				$i % 32};
		}
		if ($type != "decode") {
			$reslutstr = trim($key_b . base64_encode($reslutstr), "==");
			$reslutstr = substr(md5($reslutstr), 0, 8) . $reslutstr;
		}
		return $reslutstr;
	}
}
