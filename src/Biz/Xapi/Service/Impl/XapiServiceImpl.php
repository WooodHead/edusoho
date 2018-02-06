<?php

namespace Biz\Xapi\Service\Impl;

use AppBundle\Common\ArrayToolkit;
use AppBundle\Common\Exception\AccessDeniedException;
use Biz\BaseService;
use Biz\System\Service\SettingService;
use Biz\Task\Service\TaskService;
use Biz\Xapi\Dao\ActivityWatchLogDao;
use Biz\Xapi\Dao\StatementArchiveDao;
use Biz\Xapi\Dao\StatementDao;
use Biz\Xapi\Service\XapiService;
use Codeages\Biz\Framework\Dao\BatchUpdateHelper;
use QiQiuYun\SDK\HttpClient\Client;
use QiQiuYun\SDK\QiQiuYunSDK;

class XapiServiceImpl extends BaseService implements XapiService
{
    public function createStatement($statement)
    {
        if (empty($this->biz['user'])) {
            throw new AccessDeniedException('user is not login.');
        }

        $statement['version'] = $this->biz['xapi.options']['version'];
        $statement['uuid'] = $this->generateUUID();

        return $this->getStatementDao()->create($statement);
    }

    public function getStatement($id)
    {
        return $this->getStatementDao()->get($id);
    }

    protected function generateUUID()
    {
        mt_srand((float) microtime() * 10000);
        $charid = strtoupper(md5(uniqid(rand(), true)));
        $hyphen = chr(45);
        $uuid = ''.substr($charid, 0, 8).$hyphen.substr($charid, 8, 4).$hyphen.substr($charid, 12, 4).$hyphen.substr($charid, 16, 4).$hyphen.substr($charid, 20, 12);

        return $uuid;
    }

    public function updateStatementsPushedByStatementIds($statementIds)
    {
        $batchUpdateHelper = new BatchUpdateHelper($this->getStatementDao());
        foreach ($statementIds as $statementId) {
            $batchUpdateHelper->add('id', $statementId, array(
                'status' => 'pushed',
                'push_time' => time(),
            ));
        }
        $batchUpdateHelper->flush();
    }

    public function updateStatementsPushingByStatementIds($statementIds)
    {
        $batchUpdateHelper = new BatchUpdateHelper($this->getStatementDao());
        foreach ($statementIds as $statementId) {
            $batchUpdateHelper->add('id', $statementId, array(
                'status' => 'pushing',
            ));
        }
        $batchUpdateHelper->flush();
    }

    public function updateStatementsPushedAndDataByStatementData($pushStatementsData)
    {
        $batchUpdateHelper = new BatchUpdateHelper($this->getStatementDao());
        foreach ($pushStatementsData as $id => $data) {
            $batchUpdateHelper->add('id', $id, array(
                'status' => 'pushed',
                'push_time' => time(),
                'data' => $data,
            ));
        }
        $batchUpdateHelper->flush();
    }

    public function updateStatementsConvertedAndDataByStatementData($pushStatementsData)
    {
        $batchUpdateHelper = new BatchUpdateHelper($this->getStatementDao());
        foreach ($pushStatementsData as $id => $data) {
            $batchUpdateHelper->add('id', $id, array(
                'status' => 'converted',
                'push_time' => time(),
                'data' => $data,
            ));
        }
        $batchUpdateHelper->flush();
    }

    public function updateStatusPushedAndPushedTimeByUuids($uuids, $pushTime)
    {
        return $this->getStatementDao()->callbackStatusPushedAndPushedTimeByUuids($uuids, $pushTime);
    }

    public function searchStatements($conditions, $orders, $start, $limit)
    {
        return $this->getStatementDao()->search($conditions, $orders, $start, $limit);
    }

    public function countStatements($conditions)
    {
        return $this->getStatementDao()->count($conditions);
    }

    public function getWatchLog($id)
    {
        return $this->getActivityWatchLogDao()->get($id);
    }

    public function findWatchLogsByIds($ids)
    {
        return $this->getActivityWatchLogDao()->findByIds($ids);
    }

    public function getLatestWatchLogByUserIdAndActivityId($userId, $activityId, $isPush = 0)
    {
        return $this->getActivityWatchLogDao()->getLatestWatchLogByUserIdAndActivityId($userId, $activityId, $isPush);
    }

    public function createWatchLog($watchLog)
    {
        return $this->getActivityWatchLogDao()->create($watchLog);
    }

    public function updateWatchLog($id, $watchLog)
    {
        return $this->getActivityWatchLogDao()->update($id, $watchLog);
    }

    public function searchWatchLogs($conditions, $orderBys, $start, $limit)
    {
        return $this->getActivityWatchLogDao()->search($conditions, $orderBys, $start, $limit);
    }

    public function watchTask($taskId, $watchTime)
    {
        $user = $this->getCurrentUser();
        $task = $this->getTaskService()->tryTakeTask($taskId);

        if (!in_array($task['type'], array('video', 'audio', 'live'))) {
            return;
        }
        $watchLog = $this->getLatestWatchLogByUserIdAndActivityId($user['id'], $task['activityId']);
        if (empty($watchLog) || $watchLog['updated_time'] < time() - 30 * 60) {
            $log = array(
                'user_id' => $user['id'],
                'activity_id' => $task['activityId'],
                'course_id' => $task['courseId'],
                'task_id' => $task['id'],
                'watched_time' => $watchTime,
            );

            $this->createWatchLog($log);
        } else {
            $this->getActivityWatchLogDao()->wave(array($watchLog['id']), array('watched_time' => $watchTime));
        }
    }

    public function archiveStatement()
    {
        try {
            $this->beginTransaction();
            $statements = $this->searchStatements(
                array(
                    'status' => 'pushed',
                ),
                array('push_time' => 'ASC'),
                0,
                1000
            );

            if (!empty($statements)) {
                $archives = array();
                foreach ($statements as $statement) {
                    $archives[] = ArrayToolkit::parts($statement, array(
                        'uuid', 'version', 'push_time', 'user_id', 'verb', 'target_id', 'target_type', 'status', 'data', 'occur_time', 'created_time',
                    ));
                }
                $this->getStatementArchiveDao()->batchCreate($archives);
                foreach ($statements as $statement) {
                    $this->getStatementDao()->delete($statement['id']);
                }
            }

            $this->commit();
        } catch (\Exception $e) {
            $this->rollback();
            $this->getLogger()->error($e);
        }
    }

    public function getXapiSdk()
    {
        $settings = $this->getSettingService()->get('storage', array());
        $siteSettings = $this->getSettingService()->get('site', array());
        $xapiSetting = $this->getSettingService()->get('xapi', array());

        $pushUrl = !empty($xapiSetting['push_url']) ? $xapiSetting['push_url'] : 'https://lrs.qiqiuyun.net/v1/xapi/';

        $siteName = empty($siteSettings['name']) ? 'none' : $siteSettings['name'];
        $siteUrl = empty($siteSettings['url']) ? '' : $siteSettings['url'];
        $accessKey = empty($settings['cloud_access_key']) ? 'none' : $settings['cloud_access_key'];
        $secretKey = empty($settings['cloud_secret_key']) ? 'none' : $settings['cloud_secret_key'];

        $qiqiuyunSdk = new QiQiuYunSDK(
            array(
                'host' => $siteUrl,
                'access_key' => $accessKey,
                'secret_key' => $secretKey,
                'service' => array(
                    'xapi' => array(
                        'school_name' => $siteName,
                    ),
                ),
            ),
            $this->biz['logger'],
            new Client(array(
                array(
                    'base_uri' => $pushUrl
                ),
            ))
        );

        return $qiqiuyunSdk->getXAPIService();
    }

    /**
     * @return StatementDao
     */
    protected function getStatementDao()
    {
        return $this->createDao('Xapi:StatementDao');
    }

    /**
     * @return ActivityWatchLogDao
     */
    protected function getActivityWatchLogDao()
    {
        return $this->createDao('Xapi:ActivityWatchLogDao');
    }

    /**
     * @return TaskService
     */
    protected function getTaskService()
    {
        return $this->createService('Task:TaskService');
    }

    /**
     * @return StatementArchiveDao
     */
    protected function getStatementArchiveDao()
    {
        return $this->createDao('Xapi:StatementArchiveDao');
    }

    /**
     * @return SettingService
     */
    protected function getSettingService()
    {
        return $this->createService('System:SettingService');
    }
}
