<?php

/**
 * @brief 权益金交易模块
 * @class Account
 * @note  前台
 */
class Account extends IController implements userAuthorization
{
    public $layout = 'ucenter';

    public function init()
    {
    }

    //发布页面
    function publish_page(){
        $this->layout = 'site';
        //获取个人可转让权益金
        $account_data =  Api::run("getPartnerCanChange",$this->user['user_id']);
        $this->setRenderData(array(
			'account_data' => $account_data,
        ));
        
        $this->redirect('publish_page');
    }

    //转让失败
    function error(){
        $this->layout = 'site';
        $this->redirect('error');
    }

    // 转让成功
    function success(){
        $this->layout = 'site';
        $this->redirect('success');
    }

    //发布转让
    function publish()
    {
        //防止表单重复提交
        if (IReq::get('timeKey')) {
            if (ISafe::get('timeKey') == IReq::get('timeKey')) {
                IError::show(403, '转让数据不能被重复提交');
            }
            ISafe::set('timeKey', IReq::get('timeKey'));
        }

        //权益金ID
        $account_id = IFilter::act(IReq::get('account_id'));
        if (empty($account_id)) {
            $this->redirect('error&msg=请选择转让权益金');
            exit();
        }

        //检测是否有售卖权限
        $partneraccountObj = new IModel('partner_account');
        $partnerRow = $partneraccountObj->getObj('user_id = "' . $this->user['user_id'] . '" and id=' . $account_id);
        if (empty($partnerRow) || $partnerRow['is_sale'] == 0) {
            $this->redirect('error&msg=该权益金没有转让权限');
            exit();
        }

        //折扣
        $discount = IFilter::act(IReq::get('discount'));
        if ($discount <= 0 || $discount > 10) {
            $this->redirect('error&msg=请填写正确的折扣信息');
            exit();
        }

        $discount = sprintf("%.1f", $discount);

        //数量
        $num = IFilter::act(IReq::get('num'));
        $exp_num = explode(".", $num);
        if (empty($num) || count($exp_num) > 2 || $num < 100) {
            $this->redirect('error&msg=请填写正确的数量');
            exit();
        }

        //数量大于余额 或  大于 余额-总购买 =可销售的
        if ($num > $partnerRow['balance'] || $num > $partnerRow['balance'] - $partnerRow['buy_total']) {
            $this->redirect('error&msg=超过可转让数量(购买的不可转让)');
            exit();
        }

        //标题[折扣]转让[数量]个，[类别]类权益金,大家赶紧购买吧
        $title = "[{$discount}]转让[{$num}]个,[{$partnerRow['account_type']}]类权益金,大家赶紧购买吧";

        //构造数据
        $data = array(
            'nid' =>  $this->genChangeNid(),
            'title' => $title,
            'account_type' => $partnerRow["account_type"],
            'appid' => $partnerRow["appid"],
            'discount' => $discount,
            'num' => $num,
            'change_user_id' => $this->user['user_id'],
            'change_username' => $this->user['username'],
            'change_time' =>  ITime::getDateTime(),
            'status' => 0,
        );

        //插入转让信息表
        $tb_partner_account_change = new IModel('partner_account_change');
        $tb_partner_account_change->setData($data);
        $change_id = $tb_partner_account_change->add();
        if (!$change_id) {
            $tb_partner_account_change->rollback();
            $this->redirect('error&msg=转让权益金失败');
            exit();
        }

        //减少权益金数量
        $amount = $partnerRow['balance'] - $num;
        $partneraccountObj->setData(array("balance" => $amount));
        $flag = $partneraccountObj->update("user_id = " . $partnerRow['user_id'] . " and id='" . $partnerRow['id'] . "'");
        if (!$flag) {
            $partneraccountObj->rollback();
            $this->redirect('error&msg=转让权益金失败');
            exit();
        }

        //资金日志
        $tb_account_log = new IModel("account_log");
        $insertData = array(
            'admin_id'  => 0,
            'user_id'   => $partnerRow['user_id'],
            'event'     => '9',
            'note'      => "转让" . $partnerRow['account_type'] . '类权益金，：' . $num . '个',
            'amount'    => -$num,
            'amount_log' => $amount,
            'type'      => '1',
            'time'      => ITime::getDateTime(),
            'appid'     =>  $partnerRow['appid'],
            'account_type' => $partnerRow['account_type'],
        );

        $tb_account_log->setData($insertData);
        $flag = $tb_account_log->add();
        if (!$flag) {
            $tb_account_log->rollback();
            $this->redirect('error&msg=转让权益金失败');
            exit();
        }

        $tb_account_log->commit();

        $this->redirect("success&num={$num}");
    }

    //撤销发布(下架) 把未支付的也回退回去
    function cancel()
    {
        $nid = IFilter::act(IReq::get('nid'));
        if (empty($nid)) {
            IError::show(403, '参数错误');
        }

        //转让信息
        $changeObj = new IModel('partner_account_change');
        $changeRow = $changeObj->getObj('change_user_id="' . $this->user['user_id'] . '" and nid="' . $nid . '"');
        if (empty($changeRow)) {
            IError::show(403, '权益金信息不存在');
        }

        //购买信息
        $buyObj = new IQuery("partner_account_buy");
        $buyObj->where  = 'nid = "' . $nid . '" and status=0';
        $buyObj->fields = 'sum(num) as num_total';
        $array_buy = $buyObj->find();

        //如果有购买未付款的
        $num_totoal_no_pay = 0;
        if (!empty($array_buy)) {
            //修改购买数据
            $buyinfoObj = new IModel('partner_account_buy');
            $num_totoal_no_pay = $array_buy['0']['num_total'];

            $buyinfoObj->setData(array('status' => 2));
            $flag = $buyinfoObj->update('nid = "' . $nid . '" and status=0');
            if (!$flag) {
                $buyinfoObj->rollback();
                IError::show(403, '撤销失败');
            }
        }

        //修改转让权益金信息
        $changeObj->setData(array('status' => 2, 'sale_num' => $changeRow['sale_num'] - $num_totoal_no_pay));
        $flag = $changeObj->update('nid = "' . $nid . '" and status=0');
        if (!$flag) {
            $changeObj->rollback();
            IError::show(403, '撤销失败');
        }

        //修改权益金账户
        $return_partner_account_num = $changeRow['num'] - $changeRow['sale_num'] + $num_totoal_no_pay; //返回回去的权益金

        $query = new IQuery("partner_account");
        $query->where  = 'user_id="' . $changeRow['change_user_id'] . '" and appid="' . $changeRow['appid'] . '" and account_type="' . $changeRow['account_type'] . '"';
        $query->fields = 'buy_total,balance,user_id,appid,account_type';
        $array_user = $query->find();

        $user_info = $array_user[0];
        $amount = $user_info['balance'] + $return_partner_account_num;

        //资金增加
        $tb_partner_account = new IModel('partner_account');
        $tb_partner_account->setData(array("balance" => $amount));
        $flag = $tb_partner_account->update("user_id = " . $changeRow['change_user_id'] . " and appid='" . $changeRow['appid'] . "' and account_type='" . $changeRow['account_type'] . "'");
        if (!$flag) {
            $tb_partner_account->rollback();
            IError::show(403, '撤销失败');
        }

        //新增资金记录
        $insertData = array(
            'admin_id'  => 0,
            'user_id'   => $changeRow['change_user_id'],
            'event'     => '11',
            'note'      => "撤销发布[" . $changeRow['nid'] . "]" . $changeRow['account_type'] . '类权益金，：' .  $return_partner_account_num . '个',
            'amount'    => $return_partner_account_num,
            'amount_log' => $amount,
            'type'      => '0',
            'time'      => ITime::getDateTime(),
            'appid'     =>  $changeRow['appid'],
            'account_type' => $changeRow['account_type'],
        );
        $tb_account_log = new IModel("account_log");
        $tb_account_log->setData($insertData);
        $flag = $tb_account_log->add();
        if (!$flag) {
            $tb_account_log->rollback();
            IError::show(403, '撤销失败');
        }

        $tb_account_log->commit();
        $this->sale();
    }

    //购买权益金
    function buychange()
    {
        $nid = IFilter::act(IReq::get('nid'));
        if (empty($nid)) {
            IError::show(403, '参数错误');
        }

        $num = IFilter::act(IReq::get('num'));
        $exp_num = explode(".", $num);
        if (empty($num) || count($exp_num) > 2 || $num < 100) {
            IError::show(403, '请填写正确的数量');
        }


        $changeObj = new IModel('partner_account_change');
        $changeRow = $changeObj->getObj('nid="' . $nid . '"');
        if (empty($changeRow)) {
            IError::show(403, '权益金信息不存在');
        }

        if ($changeRow['change_user_id'] == $this->user['user_id']) {
            IError::show(403, '不能购买自己发布的权益金');
        }

        if ($changeRow['status'] != 0) {
            IError::show(403, '该权益金已售罄或已下架');
        }

        //购买者余额检测
        $memberObj  = new IModel('member');
        $memberRow  = $memberObj->getObj('user_id = ' . $this->user['user_id']);

        if ($memberRow['balance'] < $num) {
            IError::show(403, '余额不足');
        }

        $can_buy_num = $changeRow['num'] - $changeRow['sale_num'];

        if ($num > $can_buy_num) {
            IError::show(403, '购买数量不足');
        }

        //增加转让已卖数量
        $change_data = array(
            'sale_num' => $changeRow['sale_num'] + $num,
        );
        $changeObj->setData($change_data);
        $flag = $changeObj->update('nid="' . $nid . '"');
        if (!$flag) {
            $changeObj->rollback();
            IError::show(403, '购买权益金失败');
        }

        //增加购买记录
        $buy_data = array(
            'nid' =>  $changeRow['nid'],
            'num' => $num,
            'buy_user_id' => $this->user['user_id'],
            'buy_username' => $this->user['username'],
            'buy_time' =>  ITime::getDateTime(),
            'money' =>  $num * $changeRow['discount'] / 10,
            'status' => 0,
        );

        $buyObj = new IModel('partner_account_buy');
        $buyObj->setData($buy_data);
        $flag = $buyObj->add();
        if (!$flag) {
            $buyObj->rollback();
            IError::show(403, '购买权益金失败');
        }

        $buyObj->commit();

        $this->buy();
    }

    //支付购买权益金
    function paychange()
    {
        $buy_id = IFilter::act(IReq::get('buy_id'));
        if (empty($buy_id)) {
            IError::show(403, '参数错误');
        }
        $buyObj = new IQuery("partner_account_buy as a");
        $buyObj->join   = 'left join partner_account_change as b on a.nid = b.nid';
        $buyObj->where  = 'a.buy_user_id = "' . $this->user['user_id'] . '" and a.id=' . $buy_id;
        $buyObj->fields = 'a.status,a.num,a.money,a.nid,a.buy_user_id,a.buy_username,b.nid,b.account_type,b.change_user_id,b.change_username,b.appid,b.get_money,b.num as total_num,b.sale_num';
        $array_buy = $buyObj->find();

        if (empty($array_buy)) {
            IError::show(403, '记录不存在');
        }

        $buyRow = $array_buy[0];

        /*$pay_type = IFilter::act(IReq::get('pay_type'));
        if(empty($pay_type)){
            IError::show(403, '参数错误');
        }*/


        if ($buyRow['status'] != 0) {
            IError::show(403, '购买权益金已完成或已取消');
        }

        //购买者余额检测
        $memberObj  = new IModel('member');
        $memberRow  = $memberObj->getObj('user_id = ' . $buyRow['buy_user_id']);

        if ($memberRow['balance'] < $buyRow['money']) {
            IError::show(403, '余额不足');
        }

        //购买者余额减少
        $amount = $memberRow['balance']  - $buyRow['money'];
        $mem_data = array(
            'balance' => $amount,
        );

        $memberObj->setData($mem_data);
        $flag = $memberObj->update("user_id = " . $buyRow['buy_user_id']);
        if (!$flag) {
            $memberObj->rollback();
            IError::show(403, '支付失败');
        }

        //转让者资金记录
        $insertData = array(
            'admin_id'  => 0,
            'user_id'   => $buyRow['change_user_id'],
            'event'     => '3',
            'note'      => "购买" . $buyRow['account_type'] . '类权益金，：' . $buyRow['num'] . '个，花费余额' . $buyRow['money'] . "元",
            'amount'    => -$buyRow['money'],
            'amount_log' => $amount,
            'type'      => '1',
            'time'      => ITime::getDateTime(),
            'appid'     =>  $buyRow['appid'],
            'account_type' => $buyRow['account_type'],
        );

        $tb_account_log = new IModel("account_log");
        $tb_account_log->setData($insertData);
        $flag = $tb_account_log->add();
        if (!$flag) {
            $tb_account_log->rollback();
            IError::show(403, '支付失败');
        }

        //新增购买者权益金账户
        //检测资金账号
        $tb_partner_account = new IModel('partner_account');
        $accountRow = $tb_partner_account->getObj('user_id= "' . $buyRow['buy_user_id'] . '" and appid = "' . $buyRow['appid'] . '" and account_type="' . $buyRow['account_type'] . '"');
        if (empty($accountRow)) {
            //账号不存在 新增资金账户
            $accuntArray = array(
                'user_id' => $buyRow['buy_user_id'],
                'appid' => $buyRow['appid'],
                'balance' => 0,
                'addtime'    => ITime::getDateTime(),
                'account_type' => $buyRow['account_type'],
            );
            $tb_partner_account->setData($accuntArray);
            $account_id = $tb_partner_account->add();
            if (!$account_id) {
                $tb_partner_account->rollback();
                IError::show(403, '支付失败');
            }
        }

        $query = new IQuery("partner_account");
        $query->where  = 'user_id="' . $buyRow['buy_user_id'] . '" and appid="' . $buyRow['appid'] . '" and account_type="' . $buyRow['account_type'] . '"';
        $query->fields = 'buy_total,balance,user_id,appid,account_type';
        $array_user = $query->find();

        $user_info = $array_user[0];
        $amount = $user_info['balance'] + $buyRow['num'];
        $buy_total = $user_info['buy_total'] + $buyRow['num'];

        //资金增加
        $tb_partner_account->setData(array("balance" => $amount, 'buy_total' => $buy_total));
        $flag = $tb_partner_account->update("user_id = " . $buyRow['buy_user_id'] . " and appid='" . $buyRow['appid'] . "' and account_type='" . $buyRow['account_type'] . "'");
        if (!$flag) {
            $tb_partner_account->rollback();
            IError::show(403, '支付失败');
        }

        //新增资金记录
        $insertData = array(
            'admin_id'  => 0,
            'user_id'   => $buyRow['buy_user_id'],
            'event'     => '10',
            'note'      => "购买" . $buyRow['account_type'] . '类权益金，：' . $buyRow['num'] . '个',
            'amount'    => $buyRow['num'],
            'amount_log' => $amount,
            'type'      => '0',
            'time'      => ITime::getDateTime(),
            'appid'     =>  $buyRow['appid'],
            'account_type' => $buyRow['account_type'],
        );
        $tb_account_log->setData($insertData);
        $flag = $tb_account_log->add();
        if (!$flag) {
            $tb_account_log->rollback();
            IError::show(403, '支付失败');
        }

        //修改购买信息
        $buyinfoObj = new IModel('partner_account_buy');
        $buyinfoObj->setData(array('status' => 1));
        $flag = $buyinfoObj->update('buy_user_id = "' . $buyRow['buy_user_id'] . '" and id=' . $buy_id);
        if (!$flag) {
            $buyinfoObj->rollback();
            IError::show(403, '支付失败');
        }

        //转让信息修改
        $change_data = array(
            'get_money' => $buyRow['get_money'] + $buyRow['money'],
        );

        //如果没有未支付的购买权益金 售罄
        $is_no_pay = $buyinfoObj->getObj('nid="' . $buyRow['nid'] . '" and status=0');
        if (empty($is_no_pay) && $buyRow['total_num'] == $buyRow['sale_num']) {
            $change_data['status'] = 1;
        }

        $changeObj = new IModel('partner_account_change');
        $changeObj->setData($change_data);
        $flag = $changeObj->update('change_user_id = "' . $buyRow['change_user_id'] . '" and nid=' . $buyRow['nid']);
        if (!$flag) {
            $changeObj->rollback();
            IError::show(403, '支付失败');
        }

        //转让者资金信息修改
        $query = new IQuery("partner_account");
        $query->where  = 'user_id="' . $buyRow['change_user_id'] . '" and appid="' . $buyRow['appid'] . '" and account_type="' . $buyRow['account_type'] . '"';
        $query->fields = 'sale_total';
        $array_user = $query->find();

        $change_info = $array_user[0];

        $tb_partner_account->setData(array('sale_total' => $change_info['sale_total'] + $buyRow['num']));
        $flag = $tb_partner_account->update("user_id = " . $buyRow['change_user_id'] . " and appid='" . $buyRow['appid'] . "' and account_type='" . $buyRow['account_type'] . "'");
        if (!$flag) {
            $tb_partner_account->rollback();
            IError::show(403, '支付失败');
        }

        //转让者余额增加
        $memberRow  = $memberObj->getObj('user_id = ' . $buyRow['change_user_id']);

        $amount = $memberRow['balance']  + $buyRow['money'];
        $mem_data = array(
            'point' => $memberRow['point'] + $buyRow['money'], //积分
            'balance' => $amount,
        );
        $memberObj->setData($mem_data);
        $flag = $memberObj->update("user_id = " . $buyRow['change_user_id']);
        if (!$flag) {
            $memberObj->rollback();
            IError::show(403, '支付失败');
        }

        //转让者资金记录
        $insertData = array(
            'admin_id'  => 0,
            'user_id'   => $buyRow['change_user_id'],
            'event'     => '12',
            'note'      => "售卖" . $buyRow['account_type'] . '类权益金，：' . $buyRow['num'] . '个，获得金额' . $buyRow['money'] . "元",
            'amount'    => $buyRow['money'],
            'amount_log' => $amount,
            'type'      => '0',
            'time'      => ITime::getDateTime(),
            'appid'     =>  $buyRow['appid'],
            'account_type' => $buyRow['account_type'],
        );

        $tb_account_log->setData($insertData);
        $flag = $tb_account_log->add();
        if (!$flag) {
            $tb_account_log->rollback();
            IError::show(403, '支付失败');
        }

        $tb_account_log->commit();

        $this->buy();
    }

    //取消支付
    function cancelpay()
    {
        $buy_id = IFilter::act(IReq::get('buy_id'));
        if (empty($buy_id)) {
            IError::show(403, '参数错误');
        }
        $buyObj = new IQuery("partner_account_buy as a");
        $buyObj->join   = 'left join partner_account_change as b on a.nid = b.nid';
        $buyObj->where  = 'a.buy_user_id = "' . $this->user['user_id'] . '" and a.id=' . $buy_id;
        $buyObj->fields = 'a.status,a.num,a.money,a.nid,a.buy_user_id,a.buy_username,b.nid,b.account_type,b.change_user_id,b.change_username,b.appid,b.get_money,b.num as total_num,b.sale_num';
        $array_buy = $buyObj->find();

        if (empty($array_buy)) {
            IError::show(403, '记录不存在');
        }

        $buyRow = $array_buy[0];

        if ($buyRow['status'] != 0) {
            IError::show(403, '购买权益金已完成或已取消');
        }

        //修改购买数据
        $buyinfoObj = new IModel('partner_account_buy');
        $buyinfoObj->setData(array('status' => 2));
        $flag = $buyinfoObj->update('id = "' . $buy_id . '" and status=0');
        if (!$flag) {
            $buyinfoObj->rollback();
            IError::show(403, '取消支付失败');
        }

        //回退转让权益金信息
        $changeObj = new IModel('partner_account_change');
        $change_data = array(
            'sale_num' => $buyRow['sale_num'] - $buyRow['num'],
        );
        $changeObj->setData($change_data);
        $flag = $changeObj->update('nid = "' . $buyRow['nid'] . '"');
        if (!$flag) {
            $changeObj->rollback();
            IError::show(403, '取消支付失败');
        }

        $changeObj->commit();
        $this->buy();
    }

    //我发布的权益金转让记录
    function sale()
    {
        $query = Api::run("getChangeList", $this->user['user_id']);
        $this->setRenderData(array(
            'change' => $query,
        ));

        $this->redirect('sale');
    }

    //发布详情
    function sale_detail()
    {
        $nid = IFilter::act(IReq::get('nid'));
        if (empty($nid)) {
            IError::show(403, '转让权益金详情不存在');
        }

        $changeObj = new IModel('partner_account_change');
        $changeRow = $changeObj->getObj('change_user_id = "' . $this->user['user_id'] . '" and nid="' . $nid . '"');

        //购买者信息
        $buyquery = Api::run("getBuyList");

        $this->setRenderData(array(
            'change_detail' => $changeRow,
            'buy_list' => $buyquery->find(),
        ));


        $this->redirect('sale_detail');
    }


    //我购买的权益金转让记录
    function buy()
    {
        $query = Api::run("getBuyList", $this->user['user_id']);
        $this->setRenderData(array(
            'buy' => $query,
        ));
        $this->redirect('buy');
    }

    //购买详情页面
    function buy_detail()
    {
        $this->redirect('buy_detail');
    }

    //生成转让号
    private function genChangeNid()
    {
        $changeObj = new IModel('partner_account_change');
        $changeRow = $changeObj->getObj(false, 'max(id) as id,nid');
        if (!empty($changeRow)) {
            $today = date('Ym');
            $pid = str_replace($today, '', $changeRow['nid']);
            if (strlen($pid) == strlen($changeRow['nid'])) {
                $nid = $today . '00001';
            } else {
                $pid = $today . str_pad($pid, 5, '0', STR_PAD_LEFT);
                $nid = $pid + 1;
            }
        } else {
            $today = date('Ym');
            $nid = $today . '00001';
        }

        return  $nid;
    }
}
