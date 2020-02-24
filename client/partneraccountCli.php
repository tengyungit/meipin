<?php

/**
 * @brief 权益金购买回退操作。
 *        让操作系统在间隔时间执行次脚本
 * @date 2020/2/24
 * @author tengyun
 */
$iweb = dirname(__FILE__) . "/../lib/iweb.php";
$config = dirname(__FILE__) . "/../config/config.php";
require($iweb);

class partneraccountCli extends IApplication
{
    //清理过期的时间（分钟）
    private static $timeStep = 10;

    //执行入口
    public function execRequest()
    {
        $partnder_buy = new IModel('partner_account_buy');
        $the_time = self::$timeStep;

        //查询超时未付款的权益金购买
        $partner_buy_data = $partnder_buy->query("status = 0 and timestampdiff(minute,buy_time,NOW()) >= {$the_time}  ", "id,nid,num,buy_user_id,money");
        foreach ($partner_buy_data as $kay => $val) {
            $this->cancelpay($val['id'],$val['buy_user_id']);
        }
    }

    //取消购买
    private function cancelpay($buy_id,$user_id)
    {
        $buyObj = new IQuery("partner_account_buy as a");
        $buyObj->join   = 'left join partner_account_change as b on a.nid = b.nid';
        $buyObj->where  = 'a.buy_user_id = "' . $user_id. '" and a.id=' . $buy_id;
        $buyObj->fields = 'a.status,a.num,a.money,a.nid,a.buy_user_id,a.buy_username,b.nid,b.account_type,b.change_user_id,b.change_username,b.appid,b.get_money,b.num as total_num,b.sale_num';
        $array_buy = $buyObj->find();

        if (empty($array_buy)) {
            echo 'no data';exit();
        }

        $buyRow = $array_buy[0];

        if ($buyRow['status'] != 0) {
           echo 'isover';exit();
        }

        //修改购买数据
        $buyinfoObj = new IModel('partner_account_buy');
        $buyinfoObj->setData(array('status' => 2, 'do_time' => ITime::getDateTime()));
        $flag = $buyinfoObj->update('id = "' . $buy_id . '" and status=0');
        if (!$flag) {
            $buyinfoObj->rollback();
            echo 'fail';exit();
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
            echo 'fail';exit();
        }

        $changeObj->commit();
        echo $buy_id.'ok';
    }
}

$configData = include($config);
$configData['basePath'] = dirname(__FILE__) . '/../';
IWeb::createApp("partneraccountCli", $configData)->run();
