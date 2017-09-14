<?php

namespace AppBundle\Controller\Admin;

use AppBundle\Common\MathToolkit;
use Biz\Account\Service\AccountProxyService;
use Imagine\Image\Box;
use Imagine\Gd\Imagine;
use AppBundle\Common\Paginator;
use AppBundle\Common\FileToolkit;
use AppBundle\Common\ArrayToolkit;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class CoinController extends BaseController
{
    public function settingsAction(Request $request)
    {
        $postedParams = $request->request->all();

        $coinSettingsSaved = $this->getSettingService()->get('coin', array());

        $default = array(
            'coin_enabled' => 0,
            'cash_model' => 'none',
            'cash_rate' => 1,
            'coin_name' => '虚拟币',
            'coin_content' => '',
            'coin_picture' => '',
            'coin_picture_50_50' => '',
            'coin_picture_30_30' => '',
            'coin_picture_20_20' => '',
            'coin_picture_10_10' => '',
            'charge_coin_enabled' => '',
        );
        $coinSettingsSaved = array_merge($default, $coinSettingsSaved);

        if ($request->getMethod() == 'POST') {
            $fields = $request->request->all();

            $coinSettingsPosted = ArrayToolkit::parts($fields, array(
                'coin_enabled',
                'cash_model',
                'cash_rate',
                'coin_name',
                'coin_content',
                'coin_picture',
                'coin_picture_50_50',
                'coin_picture_30_30',
                'coin_picture_20_20',
                'coin_picture_10_10',
                'charge_coin_enabled',
            ));

            $coinSettings = array_merge($coinSettingsSaved, $coinSettingsPosted);

            $coinSettings['coin_content'] = $this->purifyHtml($coinSettings['coin_content'], true);

            $this->getSettingService()->set('coin', $coinSettings);
            $this->getLogService()->info('system', 'update_settings', '更新Coin虚拟币设置', $coinSettingsPosted);
            $this->setFlashMessage('success', 'site.save.success');

            return $this->settingsRenderedPage($coinSettingsPosted);
        }

        return $this->settingsRenderedPage($coinSettingsSaved);
    }

    protected function savePicture(Request $request, $size)
    {
        $file = $request->files->get('coin_picture');
        $filename = 'logo_'.time().'.'.$file->getClientOriginalExtension();
        $directory = "{$this->container->getParameter('topxia.upload.public_directory')}/coin";

        $pictureFilePath = $directory.'/'.$filename;
        $pathinfo = pathinfo($pictureFilePath);

        $imagine = new Imagine();
        $rawImage = $imagine->open($pictureFilePath);

        $image = $rawImage->copy();
        $image->resize(new Box($size, $size));
        $filePath = "{$pathinfo['dirname']}/{$pathinfo['filename']}_{$size}-{$size}.{$pathinfo['extension']}";
        $imageName = "{$pathinfo['filename']}_{$size}-{$size}.{$pathinfo['extension']}";
        $image = $image->save($filePath, array('quality' => 100));

        $coin = $this->getSettingService()->get('coin', array());
        $name = "{$this->container->getParameter('topxia.upload.public_url_path')}/coin/{$imageName}";
        $path = ltrim($name, '/');

        return array($image, $path);
    }

    public function modelAction(Request $request)
    {
        $coinSettings = $this->getSettingService()->get('coin', array());

        if ($request->getMethod() == 'POST') {
            $set = $request->request->all();

            if ($set['cash_model'] == 'none') {
                $coinSettings['cash_model'] = 'none';
                $coinSettings['price_type'] = 'RMB';
                $coinSettings['cash_rate'] = $set['cash_rate'];
                $coinSettings['coin_enabled'] = 0;

                $this->getSettingService()->set('coin', $coinSettings);
                $this->setFlashMessage('success', 'site.save.success');
                goto response;
            }

            $courseSets = $this->getCourseSetService()->searchCourseSets(array(
                'parentId' => 0,
                'maxCoursePrice_GT' => 0,
            ), array('updatedTime' => 'desc'), 0, PHP_INT_MAX);

            return $this->render('admin/coin/coin-course-set.html.twig', array(
                'set' => $set,
                'items' => $courseSets,
            ));
        }

        if ($request->query->get('set')) {
            $coinSettings = $request->query->get('set');
        }

        response:

        return $this->render('admin/coin/coin-model.html.twig', array(
            'coinSettings' => $coinSettings,
        ));
    }

    public function tableAjaxAction(Request $request)
    {
        $conditions = $request->query->all();
        $type = $conditions['type'];
        $set = $conditions['set'];

        if ($type == 'course') {
            $items = $this->getCourseSetService()->searchCourseSets(array(
                'maxCoursePrice_GT' => '0.00',
                'parentId' => 0,
            ), array('updatedTime' => 'desc'), 0, PHP_INT_MAX);
        } elseif ($type == 'classroom') {
            $items = $this->getClassroomService()->searchClassrooms(array('private' => 0, 'price_GT' => '0.00'),
                array('createdTime' => 'DESC'), 0, PHP_INT_MAX);
        } elseif ($type == 'vip') {
            // todo
            $items = $this->getLevelService()->searchLevels(array('enable' => 1), array('seq' => 'asc'), 0, PHP_INT_MAX);
        }

        return $this->render('admin/coin/coin-table-setting.html.twig', array(
            'type' => $conditions['type'],
            'items' => $items,
            'set' => $set,
        ));
    }

    public function modelSaveAction(Request $request)
    {
        $coinSettings = $this->getSettingService()->get('coin', array());

        if ($request->getMethod() == 'POST') {
            $data = $request->request->all();

            $coinSettings['coin_enabled'] = 1;
            $coinSettings['cash_rate'] = $data['cash_rate'];

            if ($data['cash_model'] == 'deduction') {
                $coinSettings['price_type'] = 'RMB';
                $coinSettings['cash_model'] = 'deduction';

                if (isset($data['item-rate'])) {
                    $this->updateMaxRate($data);
                }
            } else {
                $coinSettings['price_type'] = 'Coin';
                $coinSettings['cash_model'] = 'currency';
            }

            $this->getSettingService()->set('coin', $coinSettings);
        }

        $this->setFlashMessage('success', 'site.save.success');

        return $this->redirect($this->generateUrl('admin_coin_model'));
    }

    protected function updateMaxRate($data)
    {
        $type = $data['type'];
        $data = $data['item-rate'];

        if ($type == 'course') {
            foreach ($data as $key => $value) {
                $this->getCourseSetService()->updateMaxRate($key, $value);
            }
        } elseif ($type == 'classroom') {
            foreach ($data as $key => $value) {
                $this->getClassroomService()->updateClassroom($key, array('maxRate' => $value));
            }
        } elseif ($type == 'vip') {
            foreach ($data as $key => $value) {
                $this->getLevelService()->updateLevel($key, array('maxRate' => $value));
            }
        }
    }

    public function pictureAction(Request $request)
    {
        $file = $request->files->get('coin_picture');

        if (!FileToolkit::isImageFile($file)) {
            throw $this->createAccessDeniedException('图片格式不正确，请上传png, gif, jpg格式的图片文件！');
        }

        $filename = 'logo_'.time().'.'.$file->getClientOriginalExtension();
        $directory = "{$this->container->getParameter('topxia.upload.public_directory')}/coin";
        $file = $file->move($directory, $filename);

        $size = getimagesize($file);
        $width = $size[0];
        $height = $size[1];

        if ($width < 50 || $height < 50 || $width != $height) {
            throw $this->createAccessDeniedException('图片大小不正确，请上传超过50*50的等比例图片！');
        }

        list($coin_picture_50_50, $url_50_50) = $this->savePicture($request, 50);
        list($coin_picture_30_30, $url_30_30) = $this->savePicture($request, 30);
        list($coin_picture_20_20, $url_20_20) = $this->savePicture($request, 20);
        list($coin_picture_10_10, $url_10_10) = $this->savePicture($request, 10);

        $coin = $this->getSettingService()->get('coin', array());

        $coin['coin_picture'] = $coin['coin_picture_50_50'] = $url_50_50;
        $coin['coin_picture_30_30'] = $url_30_30;
        $coin['coin_picture_20_20'] = $url_20_20;
        $coin['coin_picture_10_10'] = $url_10_10;

        $this->getSettingService()->set('coin', $coin);

        $this->getLogService()->info('system', 'update_settings', '更新虚拟币图片',
            array('coin_picture' => $coin['coin_picture']));

        $response = array(
            'path' => $coin['coin_picture'],
            'path_50_50' => $coin['coin_picture_50_50'],
            'path_30_30' => $coin['coin_picture_30_30'],
            'path_20_20' => $coin['coin_picture_20_20'],
            'path_10_10' => $coin['coin_picture_10_10'],
            'url' => $this->container->get('templating.helper.assets')->getUrl($coin['coin_picture']),
            'coin_picture_50_50' => $this->container->get('templating.helper.assets')->getUrl($coin['coin_picture_50_50']),
            'coin_picture_30_30' => $this->container->get('templating.helper.assets')->getUrl($coin['coin_picture_30_30']),
            'coin_picture_20_20' => $this->container->get('templating.helper.assets')->getUrl($coin['coin_picture_20_20']),
            'coin_picture_10_10' => $this->container->get('templating.helper.assets')->getUrl($coin['coin_picture_10_10']),
        );

        return new Response(json_encode($response));
    }

    public function pictureRemoveAction(Request $request)
    {
        $setting = $this->getSettingService()->get('coin');
        $setting['coin_picture'] = '';

        $this->getSettingService()->set('coin', $setting);

        $this->getLogService()->info('system', 'update_settings', '移除虚拟币图片');

        return $this->createJsonResponse(true);
    }

    public function recordsAction(Request $request)
    {
        $fields = $request->query->all();
        $conditions['timeType'] = 'oneWeek';
        $conditions['user_type'] = 'buyer';
        $conditions['amount_type'] = 'coin';

        if (!empty($fields)) {
            $conditions = array_merge($conditions, $this->filterCondition($fields));
        }

        $paginator = new Paginator(
            $this->get('request'),
            $this->getAccountProxyService()->countUserCashflows($conditions),
            20
        );

        $cashes = $this->getAccountProxyService()->searchUserCashflows(
            $conditions,
            array('created_time' => 'DESC'),
            $paginator->getOffsetCount(),
            $paginator->getPerPageCount()
        );

        foreach ($cashes as &$cash) {
            $cash = MathToolkit::multiply($cash, array('amount'), 0.01);
        }

        if (isset($conditions['type'])) {
            switch ($conditions['type']) {
                case 'inflow':
                    $inflow = $this->getAccountProxyService()->sumColumnByConditions('amount', $conditions);
                    $outflow = 0;
                    break;
                case 'outflow':
                    $outflow = $this->getAccountProxyService()->sumColumnByConditions('amount', $conditions);
                    $inflow = 0;
                    break;
                default:
                    $conditions['type'] = 'outflow';
                    $outflow = $this->getAccountProxyService()->sumColumnByConditions('amount', $conditions);
                    $conditions['type'] = 'inflow';
                    $inflow = $this->getAccountProxyService()->sumColumnByConditions('amount', $conditions);
                    break;
            }
        } else {
            $conditions['type'] = 'outflow';
            $outflow = $this->getAccountProxyService()->sumColumnByConditions('amount', $conditions);
            $conditions['type'] = 'inflow';
            $inflow = $this->getAccountProxyService()->sumColumnByConditions('amount', $conditions);
        }

        $in = $this->getAccountProxyService()->sumColumnByConditions('amount', array('type' => 'inflow', 'amount_type' => 'coin'));
        $out = $this->getAccountProxyService()->sumColumnByConditions('amount', array('type' => 'outflow', 'amount_type' => 'coin'));
        $amounts = $in - $out;

        $userIds = ArrayToolkit::column($cashes, 'user_id');
        $users = $this->getUserService()->findUsersByIds($userIds);

        return $this->render('admin/coin/coin-records.html.twig', array(
            'users' => $users,
            'cashes' => $cashes,
            'outflow' => $outflow,
            'inflow' => $inflow,
            'amounts' => $amounts,
            'paginator' => $paginator,
            'cashType' => 'Coin',
        ));
    }

    public function userRecordsAction(Request $request)
    {
        $condition['timeType'] = 'oneWeek';
        $condition['amount_type'] = 'coin';
        $condition['orderBY'] = 'desc';

        $fields = $request->query->all();

        if (!empty($fields)) {
            $convertCondition = $this->convertFiltersToCondition($fields);
            $condition = array_merge($condition, $convertCondition);
        }

        if (isset($condition['userId'])) {
            if ($condition['userId'] == 0) {
                $userIds = array();
                $users = array();
                $condition['userId'] = 'null';
                goto response;
            }

            $userIds = array($condition['userId']);
            $user = $this->getUserService()->getUser($condition['userId']);
            $users = array($condition['userId'] => $user);

            response:

            return $this->render('admin/coin/coin-user-records.html.twig', array(
                'condition' => $condition,
                'userIds' => $userIds,
                'users' => $users,
            ));
        }

        $paginator = new Paginator(
            $this->get('request'),
            $this->getAccountProxyService()->countUsersByConditions($condition),
            20
        );

        $sort = $condition['orderBY'];

        unset($condition['orderBY']);

        if (isset($condition['type'])) {
            $userIds = $this->getAccountProxyService()->searchUserIdsGroupByUserIdOrderBySumColumn(
                'amount',
                $condition,
                $sort,
                $paginator->getOffsetCount(),
                $paginator->getPerPageCount()
            );
        } else {
            $userIds = $this->getAccountProxyService()->searchUserIdsGroupByUserIdOrderByBalance(
                $condition,
                $sort,
                $paginator->getOffsetCount(),
                $paginator->getPerPageCount()
            );
        }

        $users = $this->getUserService()->findUsersByIds($userIds);

        return $this->render('admin/coin/coin-user-records.html.twig', array(
            'paginator' => $paginator,
            'userIds' => $userIds,
            'users' => $users,
        ));
    }

    public function flowDetailAction(Request $request)
    {
        $userId = $request->query->get('userId');
        $timeType = $request->query->get('timeType');

        if (empty($timeType)) {
            $timeType = 'oneWeek';
        }

        $condition['timeType'] = $timeType;
        $conditions['user_type'] = 'buyer';
        $conditions['amount_type'] = 'coin';
        $conditions['user_id'] = $userId;

        $paginator = new Paginator(
            $this->get('request'),
            $this->getAccountProxyService()->countUserCashflows($conditions),
            20
        );

        $cashes = $this->getAccountProxyService()->searchUserCashflows(
            $conditions,
            array('created_time' => 'DESC'),
            $paginator->getOffsetCount(),
            $paginator->getPerPageCount()
        );

        foreach ($cashes as &$cash) {
            $cash = MathToolkit::multiply($cash, array('amount'), 0.01);
        }

        $user = $this->getUserService()->getUser($userId);

        return $this->render('admin/coin/flow-detail-modal.html.twig', array(
            'user' => $user,
            'cashes' => $cashes,
            'paginator' => $paginator,
            'timeType' => $timeType,
        ));
    }

    protected function settingsRenderedPage($coinSettings)
    {
        return $this->render('admin/coin/coin-settings.html.twig', array(
            'coin_settings_posted' => $coinSettings,
        ));
    }

    public function giveCoinAction(Request $request)
    {
        if ($request->getMethod() == 'POST') {
            $fields = $request->request->all();

            $user = $this->getUserService()->getUserByNickname($fields['nickname']);

            $account = $this->getCashAccountService()->getAccountByUserId($user['id']);

            if (empty($account)) {
                $account = $this->getCashAccountService()->createAccount($user['id']);
            }

            if ($fields['type'] == 'add') {
                $this->getCashAccountService()->waveCashField($account['id'], $fields['amount']);
                $this->getLogService()->info('coin', 'add_coin', '添加 '.$user['nickname']." {$fields['amount']} 虚拟币",
                    array());
            } else {
                $this->getCashAccountService()->waveDownCashField($account['id'], $fields['amount']);
                $this->getLogService()->info('coin', 'deduct_coin', '扣除 '.$user['nickname']." {$fields['amount']} 虚拟币",
                    array());
            }
        }

        return $this->render('admin/coin/order-create-modal.html.twig', array());
    }

    public function editAction(Request $request, $id)
    {
        if ($request->getMethod() == 'POST') {
            $fields = $request->request->all();

            $account = $this->getCashAccountService()->getAccount($id);

            if ($account) {
                $user = $this->getUserService()->getUser($account['userId']);

                if ($fields['type'] == 'add') {
                    $this->getCashAccountService()->waveCashField($id, $fields['amount']);

                    $this->getLogService()->info('coin', 'add_coin', '添加 '.$user['nickname']." {$fields['amount']} 虚拟币",
                        array());
                } else {
                    $this->getCashAccountService()->waveDownCashField($id, $fields['amount']);
                    $this->getLogService()->info('coin', 'deduct_coin',
                        '扣除 '.$user['nickname']." {$fields['amount']} 虚拟币", array());
                }
            }
        }

        return $this->render('admin/coin/order-edit-modal.html.twig', array(
            'id' => $id,
        ));
    }

    public function checkNicknameAction(Request $request)
    {
        $nickname = $request->query->get('value');
        $result = $this->getUserService()->isNicknameAvaliable($nickname);

        if ($result) {
            $response = array('success' => false, 'message' => '该用户不存在');
        } else {
            $response = array('success' => true, 'message' => '');
        }

        return $this->createJsonResponse($response);
    }

    public function avatarAction(Request $request)
    {
        $user = $this->getUser();

        $form = $this->createFormBuilder()
            ->add('avatar', 'file')
            ->getForm();

        if ($request->getMethod() == 'POST') {
            $form->handleRequest($request);

            if ($form->isValid()) {
                $data = $form->getData();
                $file = $data['avatar'];

                if (!FileToolkit::isImageFile($file)) {
                    return $this->createMessageResponse('error', 'message_response.upload_pic_format_error.message');
                }

                $filenamePrefix = "user_{$user['id']}_";
                $hash = substr(md5($filenamePrefix.time()), -8);
                $ext = $file->getClientOriginalExtension();
                $filename = $filenamePrefix.$hash.'.'.$ext;

                $directory = $this->container->getParameter('topxia.upload.public_directory').'/tmp';
                $file = $file->move($directory, $filename);

                $fileName = str_replace('.', '!', $file->getFilename());

                return $this->redirect($this->generateUrl('settings_avatar_crop', array(
                    'file' => $fileName,
                )
                ));
            }
        }

        $hasPartnerAuth = $this->getAuthService()->hasPartnerAuth();

        if ($hasPartnerAuth) {
            $partnerAvatar = $this->getAuthService()->getPartnerAvatar($user['id'], 'big');
        } else {
            $partnerAvatar = null;
        }

        $fromCourse = $request->query->get('fromCourse');

        return $this->render('settings/avatar.html.twig', array(
            'form' => $form->createView(),
            'user' => $this->getUserService()->getUser($user['id']),
            'partnerAvatar' => $partnerAvatar,
            'fromCourse' => $fromCourse,
        ));
    }

    public function cashBillAction(Request $request)
    {
        if ($request->get('nickname')) {
            $user = $this->getUserService()->getUserByNickname($request->get('nickname'));

            if ($user) {
                $conditions['user_id'] = $user['id'];
            } else {
                $conditions['user_id'] = -1;
            }
        }

        $conditions['user_type'] = 'buyer';
        $conditions['amount_type'] = 'money';

        $conditions['timeType'] = $request->get('lastHowManyMonths');

        $paginator = new Paginator(
            $request,
            $this->getAccountProxyService()->countUserCashflows($conditions),
            20
        );

        $cashes = $this->getAccountProxyService()->searchUserCashflows(
            $conditions,
            array('id' => 'DESC'),
            $paginator->getOffsetCount(),
            $paginator->getPerPageCount()
        );

        foreach ($cashes as &$cash) {
            $cash = MathToolkit::multiply($cash, array('amount'), 0.01);
        }

        $userIds = ArrayToolkit::column($cashes, 'user_id');
        $users = $this->getUserService()->findUsersByIds($userIds);

        $conditions['type'] = 'inflow';
        $amountInflow = $this->getAccountProxyService()->sumColumnByConditions('amount', $conditions);

        $conditions['type'] = 'outflow';
        $amountOutflow = $this->getAccountProxyService()->sumColumnByConditions('amount', $conditions);

        return $this->render('admin/coin/cash-bill.html.twig', array(
            'cashes' => $cashes,
            'paginator' => $paginator,
            'users' => $users,
            'amountInflow' => $amountInflow ?: 0,
            'amountOutflow' => $amountOutflow ?: 0,
            'cashType' => 'RMB',
        ));
    }

    /**
     * @param [type] $cashType RMB | Coin
     */
    public function exportCsvAction(Request $request, $cashType)
    {
        $payment = $this->get('codeages_plugin.dict_twig_extension')->getDict('payment');
        $conditions = $request->query->all();

        if ($cashType == 'Coin') {
            $conditions['amount_type'] = 'coin';
            if (!empty($conditions)) {
                $conditions = $this->filterCondition($conditions);
            }
        }
        if ($cashType == 'RMB') {
            $conditions['amount_type'] = 'money';
            if (!empty($conditions)) {
                $conditions = $this->filterConditionBill($conditions);
            }
        }

        $conditions['user_type'] = 'buyer';

        $num = $this->getAccountProxyService()->countUserCashflows($conditions);
        $orders = $this->getAccountProxyService()->searchUserCashflows($conditions, array('id' => 'DESC'), 0, $num);
        $studentUserIds = ArrayToolkit::column($orders, 'user_id');

        $users = $this->getUserService()->findUsersByIds($studentUserIds);
        $users = ArrayToolkit::index($users, 'id');

        $profiles = $this->getUserService()->findUserProfilesByIds($studentUserIds);
        $profiles = ArrayToolkit::index($profiles, 'id');

        $str = '流水号,账目名称,购买者,姓名,收支,支付方式,创建时间';

        $str .= "\r\n";

        $results = array();

        foreach ($orders as $key => $order) {
            $order = MathToolkit::multiply($order, array('amount'), 0.01);
            $member = '';
            $member .= '流水号'.$order['sn'].',';
            $member .= $order['title'].',';
            $member .= $users[$order['user_id']]['nickname'].',';
            $member .= $profiles[$order['user_id']]['truename'] ? $profiles[$order['user_id']]['truename'].',' : '-'.',';

            if ($order['type'] == 'inflow') {
                $member .= '+'.$order['amount'].',';
            }

            if ($order['type'] == 'outflow') {
                $member .= '-'.$order['amount'].',';
            }

            if (!empty($order['platform'])) {
                $member .= $payment[$order['platform']].',';
            } else {
                $member .= '-'.',';
            }

            $member .= date('Y-n-d H:i:s', $order['created_time']).',';
            $results[] = $member;
        }

        $str .= implode("\r\n", $results);
        $str = chr(239).chr(187).chr(191).$str;

        $filename = sprintf('%s-order-(%s).csv', $cashType, date('Y-n-d'));

        $response = new Response();
        $response->headers->set('Content-type', 'text/csv');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$filename.'"');
        $response->headers->set('Content-length', strlen($str));
        $response->setContent($str);

        return $response;
    }

    protected function convertFiltersToCondition($condition)
    {
        $condition['orderBY'] = 'desc';
        $keyword = '';

        if (isset($condition['searchType'])) {
            if (isset($condition['keyword'])) {
                $keyword = $condition['keyword'];
            }

            if ($keyword != '') {
                switch ($condition['searchType']) {
                    case 'nickname':
                        $user = $this->getUserService()->getUserByNickname($keyword);
                        $condition['userId'] = $user ? $user['id'] : 0;
                        break;
                    case 'email':
                        $user = $this->getUserService()->getUserByEmail($keyword);
                        $condition['userId'] = $user ? $user['id'] : 0;
                        break;
                    default:
                        break;
                }
            }
            unset($condition['searchType']);
            unset($condition['keyword']);
        }

        if (isset($condition['sort'])) {
            switch ($condition['sort']) {
                case 'up':
                    $condition['orderBY'] = 'ASC';
                    break;
                case 'down':
                    $condition['orderBY'] = 'DESC';
                    break;
                default:
                    break;
            }

            unset($condition['sort']);
        }

        if (isset($condition['flowType'])) {
            switch ($condition['flowType']) {
                case 'in':
                    $condition['type'] = 'inflow';
                    break;
                case 'out':
                    $condition['type'] = 'outflow';
                    break;
                default:
                    break;
            }
            unset($condition['flowType']);
        }

        return $condition;
    }

    protected function filterConditionBill($conditions)
    {
        if (!empty($conditions['nickname'])) {
            $user = $this->getUserService()->getUserByNickname($conditions['nickname']);

            if ($user) {
                $conditions['userId'] = $user['id'];
            } else {
                $conditions['userId'] = -1;
            }
        }

        $conditions['cashType'] = 'RMB';
        $conditions['startTime'] = 0;
        $conditions['endTime'] = time();

        if (!empty($conditions['lastHowManyMonths'])) {
            switch ($conditions['lastHowManyMonths']) {
                case 'oneWeek':
                    $conditions['startTime'] = $conditions['endTime'] - 7 * 24 * 3600;
                    break;
                case 'twoWeeks':
                    $conditions['startTime'] = $conditions['endTime'] - 14 * 24 * 3600;
                    break;
                case 'oneMonth':
                    $conditions['startTime'] = $conditions['endTime'] - 30 * 24 * 3600;
                    break;
                case 'twoMonths':
                    $conditions['startTime'] = $conditions['endTime'] - 60 * 24 * 3600;
                    break;
                case 'threeMonths':
                    $conditions['startTime'] = $conditions['endTime'] - 90 * 24 * 3600;
                    break;
            }
        }

        return $conditions;
    }

    protected function filterCondition($conditions)
    {
        if (isset($conditions['keywordType'])) {
            if ($conditions['keywordType'] == 'userName') {
                $conditions['keywordType'] = 'user_id';
                $userFindbyNickName = $this->getUserService()->getUserByNickname($conditions['keyword']);
                $conditions['keyword'] = $userFindbyNickName ? $userFindbyNickName['id'] : -1;
            }
        }

        if (isset($conditions['keywordType'])) {
            $conditions[$conditions['keywordType']] = $conditions['keyword'];
            unset($conditions['keywordType']);
            unset($conditions['keyword']);
        }

        if (isset($conditions['createdTime'])) {
            $conditions['timeType'] = $conditions['createdTime'];

            unset($conditions['createdTime']);
        }

        return $conditions;
    }

    protected function getSettingService()
    {
        return $this->createService('System:SettingService');
    }

    protected function getCourseService()
    {
        return $this->createService('Course:CourseService');
    }

    protected function getAppService()
    {
        return $this->createService('CloudPlatform:AppService');
    }

    protected function getLevelService()
    {
        return $this->createService('VipPlugin:Vip:LevelService');
    }

    protected function getCashService()
    {
        return $this->createService('Cash:CashService');
    }

    protected function getCashAccountService()
    {
        return $this->createService('Cash:CashAccountService');
    }

    protected function getCashOrdersService()
    {
        return $this->createService('Cash:CashOrdersService');
    }

    protected function getLogService()
    {
        return $this->createService('System:LogService');
    }

    protected function getClassroomService()
    {
        return $this->createService('Classroom:ClassroomService');
    }

    protected function getCourseSetService()
    {
        return $this->createService('Course:CourseSetService');
    }

    /**
     * @return AccountProxyService
     */
    protected function getAccountProxyService()
    {
        return $this->createService('Account:AccountProxyService');
    }
}
