<?php
namespace iit;

use Yii;
use yii\base\InvalidConfigException;
use yii\base\InvalidParamException;
use yii\helpers\FileHelper;
use Qiniu\Storage\UploadManager;
use Qiniu\Auth;

class AdvancedAssetManager extends \yii\web\AssetManager
{
    public $syncRemote = false;
    public $enableCache = false;
    public $accessKey;
    public $secretKey;
    public $bucket;
    public $domain;

    private $_published = [];
    private $_auth;

    public function init()
    {
        parent::init();
        if ($this->syncRemote) {
            if ($this->accessKey && $this->secretKey && $this->bucket) {
                $this->_auth = new Auth($this->accessKey, $this->secretKey);
            } else {
                throw new InvalidConfigException('Please Input accessKey and secretKey and bucket');
            }
        }
    }

    public function publish($path, $options = [])
    {
        if ($this->syncRemote) {
            $path = Yii::getAlias($path);

            if (isset($this->_published[$path])) {
                return $this->_published[$path];
            }

            if (!is_string($path) || ($src = realpath($path)) === false) {
                throw new InvalidParamException("The file or directory to be published does not exist: $path");
            }

            if (is_file($src)) {
                return $this->_published[$path] = $this->uploadFile($src);
            } else {
                return $this->_published[$path] = $this->uploadDirectory($src, $options);
            }
        } else {
            return parent::publish($path, $options);
        }
    }

    protected function uploadFile($src)
    {
        $dir = $this->hash(dirname($src));
        $fileName = basename($src);
        $time = filemtime($src);
        if ($this->enableCache && ($cache = Yii::$app->cache->get($dir)) != null && $cache['time'] == $time) {
            return [$cache['src'], $cache['url']];
        }
        $upload = new UploadManager();
        $token = $this->_auth->uploadToken($this->bucket);
        list($ret, $err) = $upload->putFile($token, $dir . '/' . $fileName, $src);
        if ($err == null) {
            $url = $this->domain . '/' . $dir . '/' . $fileName;
            $this->enableCache && Yii::$app->cache->set($dir, ['src' => $src, 'url' => $url, 'time' => $time]);
            return [$src, $url];
        } else {
            return $this->publishFile($src);
        }
    }

    protected function uploadDirectory($src, $options)
    {
        $dir = $this->hash($src);
        $time = filemtime($src);
        if ($this->enableCache && ($cache = Yii::$app->cache->get($dir)) != null) {
            if ($cache['time'] == $time) {
                return [$cache['src'], $cache['url']];
            }
        }
        $upload = new UploadManager();
        $token = $this->_auth->uploadToken($this->bucket);
        $err = null;
        foreach (FileHelper::findFiles($src) as $file) {
            $remoteFile = (str_replace($src, '', $file));
            list($ret, $err) = $upload->putFile($token, $dir . $remoteFile, $file);
            if ($err != null) {
                break;
            }
        }
        if ($err == null) {
            $url = $this->domain . '/' . $dir;
            $this->enableCache == true && Yii::$app->cache->set($dir, ['src' => $src, 'url' => $url, 'time' => $time]);
            return [$src, $url];
        } else {
            return $this->publishDirectory($src, $options);
        }
    }
}
