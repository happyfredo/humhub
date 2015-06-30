<?php

namespace humhub\core\comment\models;

use Yii;

/**
 * This is the model class for table "comment".
 *
 * The followings are the available columns in table 'comment':
 * @property integer $id
 * @property string $message
 * @property integer $object_id
 * @property integer $space_id
 * @property string $object_model
 * @property string $created_at
 * @property integer $created_by
 * @property string $updated_at
 * @property integer $updated_by
 *
 * The followings are the available model relations:
 * @property PortfolioItem[] $portfolioItems
 * @property Post[] $posts
 *
 * @package humhub.modules_core.comment.models
 * @since 0.5
 */
class Comment extends \humhub\core\content\components\activerecords\ContentAddon
{

    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return 'comment';
    }

    /**
     * @return array validation rules for model attributes.
     */
    public function rules()
    {
        return array(
            array(['created_by', 'updated_by', 'space_id'], 'integer'),
            array(['message', 'created_at', 'space_id', 'updated_at'], 'safe'),
        );
    }

    /**
     * Before Delete, remove LikeCount (Cache) of target object.
     * Remove activity
     */
    public function beforeDelete()
    {
        $this->flushCache();
        return parent::beforeDelete();
    }

    /**
     * Flush comments cache
     */
    public function flushCache()
    {
        Yii::$app->cache->delete('commentCount_' . $this->object_model . '_' . $this->object_id);
        Yii::$app->cache->delete('commentsLimited_' . $this->object_model . '_' . $this->object_id);

        // delete workspace comment stats cache
        if (!empty($this->space_id)) {
            Yii::$app->cache->delete('workspaceCommentCount_' . $this->space_id);
        }
    }

    /**
     * After Saving of comments, fire an activity
     *
     * @return type
     */
    public function afterSave($insert, $changedAttributes)
    {
        // flush the cache
        $this->flushCache();

        $activity = \humhub\core\activity\models\Activity::CreateForContent($this);
        $activity->type = "CommentCreated";
        $activity->module = "comment";
        $activity->save();
        $activity->fire();

        /*
          // Handle mentioned users
          // Execute before NewCommentNotification to avoid double notification when mentioned.
          UserMentioning::parse($this, $this->message);

         */

        if ($insert) {
            $notification = new \humhub\core\comment\notifications\NewComment();
            $notification->source = $this;
            $notification->originator = $this->user;
            $notification->sendBulk($this->content->getUnderlyingObject()->getFollowers(null, true, true));
        }

        return parent::afterSave($insert, $changedAttributes);
    }

    /**
     * Returns a limited amount of comments
     *
     * @param type $model
     * @param type $id
     * @param type $limit
     * @return type
     */
    public static function GetCommentsLimited($model, $id, $limit = 2)
    {
        $cacheID = sprintf("commentsLimited_%s_%s", $model, $id);
        $comments = Yii::$app->cache->get($cacheID);

        if ($comments === false) {
            $commentCount = self::GetCommentCount($model, $id);

            $query = Comment::find();
            $query->offset($commentCount - $limit);
            $query->orderBy('created_at ASC');
            $query->limit($limit);
            $query->where(['object_model' => $model, 'object_id' => $id]);

            $comments = $query->all();
            Yii::$app->cache->set($cacheID, $comments, \humhub\models\Setting::Get('expireTime', 'cache'));
        }

        return $comments;
    }

    /**
     * Count number comments for this target object
     *
     * @param type $model
     * @param type $id
     * @return type
     */
    public static function GetCommentCount($model, $id)
    {
        $cacheID = sprintf("commentCount_%s_%s", $model, $id);
        $commentCount = Yii::$app->cache->get($cacheID);

        if ($commentCount === false) {
            $commentCount = Comment::find()->where(['object_model' => $model, 'object_id' => $id])->count();
            Yii::$app->cache->set($cacheID, $commentCount, \humhub\models\Setting::Get('expireTime', 'cache'));
        }

        return $commentCount;
    }

    /**
     * Returns a title/text which identifies this IContent.
     * e.g. Post: foo bar 123...
     *
     * @return String
     */
    public function getContentTitle()
    {
        return Yii::t('CommentModule.models_comment', 'Comment') . " \"" . \humhub\libs\Helpers::truncateText($this->message, 40) . "\"";
    }

    public function canDelete($userId = "")
    {

        if ($userId == "")
            $userId = Yii::$app->user->id;

        if ($this->created_by == $userId)
            return true;

        if (Yii::$app->user->isAdmin()) {
            return true;
        }

        if ($this->content->container instanceof \humhub\core\space\models\Space && $this->content->container->isAdmin($userId)) {
            return true;
        }

        return false;
    }

    public function getUser()
    {
        return $this->hasOne(\humhub\core\user\models\User::className(), ['id' => 'created_by']);
    }

}