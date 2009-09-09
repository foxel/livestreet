<?php
/*-------------------------------------------------------
*
*   LiveStreet Engine Social Networking
*   Copyright © 2008 Mzhelskiy Maxim
*
*--------------------------------------------------------
*
*   Official site: www.livestreet.ru
*   Contact e-mail: rus.engine@gmail.com
*
*   GNU General Public License, version 2:
*   http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
*
---------------------------------------------------------
*/

set_include_path(get_include_path().PATH_SEPARATOR.dirname(__FILE__));
require_once('mapper/Topic.mapper.class.php');

/**
 * Модуль для работы с топиками
 *
 */
class LsTopic extends Module {		
	protected $oMapperTopic;
	protected $oUserCurrent=null;
		
	/**
	 * Инициализация
	 *
	 */
	public function Init() {		
		$this->oMapperTopic=new Mapper_Topic($this->Database_GetConnect());		
		$this->oUserCurrent=$this->User_GetUserCurrent();
	}
	/**
	 * Получает дополнительные данные(объекты) для топиков по их ID
	 *
	 */
	public function GetTopicsAdditionalData($aTopicId,$aAllowData=array('user'=>array(),'blog'=>array('owner'=>array()),'vote','favourite','comment_new')) {
		func_array_simpleflip($aAllowData);
		if (!is_array($aTopicId)) {
			$aTopicId=array($aTopicId);
		}
		/**
		 * Получаем "голые" топики
		 */
		$aTopics=$this->GetTopicsByArrayId($aTopicId);
		/**
		 * Формируем ID дополнительных данных, которые нужно получить
		 */
		$aUserId=array();
		$aBlogId=array();
		$aTopicIdQuestion=array();		
		foreach ($aTopics as $oTopic) {
			if (isset($aAllowData['user'])) {
				$aUserId[]=$oTopic->getUserId();
			}
			if (isset($aAllowData['blog'])) {
				$aBlogId[]=$oTopic->getBlogId();
			}
			if ($oTopic->getType()=='question')	{		
				$aTopicIdQuestion[]=$oTopic->getId();
			}
		}
		/**
		 * Получаем дополнительные данные
		 */
		$aTopicsVote=array();
		$aFavouriteTopics=array();
		$aTopicsQuestionVote=array();
		$aTopicsRead=array();
		$aUsers=isset($aAllowData['user']) && is_array($aAllowData['user']) ? $this->User_GetUsersAdditionalData($aUserId,$aAllowData['user']) : $this->User_GetUsersAdditionalData($aUserId);
		$aBlogs=isset($aAllowData['blog']) && is_array($aAllowData['blog']) ? $this->Blog_GetBlogsAdditionalData($aBlogId,$aAllowData['blog']) : $this->Blog_GetBlogsAdditionalData($aBlogId);		
		if (isset($aAllowData['vote']) and $this->oUserCurrent) {
			$aTopicsVote=$this->Vote_GetVoteByArray($aTopicId,'topic',$this->oUserCurrent->getId());
			$aTopicsQuestionVote=$this->GetTopicsQuestionVoteByArray($aTopicIdQuestion,$this->oUserCurrent->getId());
		}	
		if (isset($aAllowData['favourite']) and $this->oUserCurrent) {
			$aFavouriteTopics=$this->GetFavouriteTopicsByArray($aTopicId,$this->oUserCurrent->getId());	
		}
		if (isset($aAllowData['comment_new']) and $this->oUserCurrent) {
			$aTopicsRead=$this->GetTopicsReadByArray($aTopicId,$this->oUserCurrent->getId());	
		}
		/**
		 * Добавляем данные к результату - списку топиков
		 */
		foreach ($aTopics as $oTopic) {
			if (isset($aUsers[$oTopic->getUserId()])) {
				$oTopic->setUser($aUsers[$oTopic->getUserId()]);
			} else {
				$oTopic->setUser(null); // или $oTopic->setUser(new UserEntity_User());
			}
			if (isset($aBlogs[$oTopic->getBlogId()])) {
				$oTopic->setBlog($aBlogs[$oTopic->getBlogId()]);
			} else {
				$oTopic->setBlog(null); // или $oTopic->setBlog(new BlogEntity_Blog());
			}
			if (isset($aTopicsVote[$oTopic->getId()])) {
				$oTopic->setVote($aTopicsVote[$oTopic->getId()]);				
			} else {
				$oTopic->setVote(null);
			}
			if (isset($aFavouriteTopics[$oTopic->getId()])) {
				$oTopic->setIsFavourite(true);
			} else {
				$oTopic->setIsFavourite(false);
			}			
			if (isset($aTopicsQuestionVote[$oTopic->getId()])) {
				$oTopic->setUserQuestionIsVote(true);
			} else {
				$oTopic->setUserQuestionIsVote(false);
			}
			if (isset($aTopicsRead[$oTopic->getId()]))	{		
				$oTopic->setCountCommentNew($oTopic->getCountComment()-$aTopicsRead[$oTopic->getId()]->getCommentCountLast());
				$oTopic->setDateRead($aTopicsRead[$oTopic->getId()]->getDateRead());
			} else {
				$oTopic->setCountCommentNew(0);
				$oTopic->setDateRead(date("Y-m-d H:i:s"));
			}						
		}
		return $aTopics;
	}
	/**
	 * Добавляет топик
	 *
	 * @param TopicEntity_Topic $oTopic
	 * @return unknown
	 */
	public function AddTopic(TopicEntity_Topic $oTopic) {
		if ($sId=$this->oMapperTopic->AddTopic($oTopic)) {
			$oTopic->setId($sId);
			if ($oTopic->getPublish()) {
				$aTags=explode(',',$oTopic->getTags());
				foreach ($aTags as $sTag) {
					$oTag=Engine::GetEntity('Topic_TopicTag');
					$oTag->setTopicId($oTopic->getId());
					$oTag->setUserId($oTopic->getUserId());
					$oTag->setBlogId($oTopic->getBlogId());
					$oTag->setText($sTag);
					$this->oMapperTopic->AddTopicTag($oTag);
				}
			}
			//чистим зависимые кеши
			$this->Cache_Clean(Zend_Cache::CLEANING_MODE_MATCHING_TAG,array('topic_new',"topic_new_user_{$oTopic->getUserId()}","topic_new_blog_{$oTopic->getBlogId()}"));						
			return $oTopic;
		}
		return false;
	}
	
	/**
	 * Удаляет теги у топика
	 *
	 * @param unknown_type $sTopicId
	 * @return unknown
	 */
	public function DeleteTopicTagsByTopicId($sTopicId) {
		return $this->oMapperTopic->DeleteTopicTagsByTopicId($sTopicId);
	}	
	/**
	 * Удаляет топик.
	 * Если тип таблиц в БД InnoDB, то удалятся всё связи по топику(комменты,голосования,избранное)
	 *
	 * @param unknown_type $sTopicId
	 * @return unknown
	 */
	public function DeleteTopic($sTopicId) {		
		$oTopic=$this->GetTopicById($sTopicId);
		//чистим зависимые кеши			
		$this->Cache_Clean(Zend_Cache::CLEANING_MODE_MATCHING_TAG,array('topic_update',"topic_update_user_{$oTopic->getUserId()}","topic_update_blog_{$oTopic->getBlogId()}"));
		$this->Cache_Delete("topic_{$sTopicId}");
		return $this->oMapperTopic->DeleteTopic($sTopicId);
	}
	/**
	 * Обновляет топик
	 *
	 * @param TopicEntity_Topic $oTopic
	 * @return unknown
	 */
	public function UpdateTopic(TopicEntity_Topic $oTopic) {
		/**
		 * Получаем топик ДО изменения
		 */
		$oTopicOld=$this->GetTopicById($oTopic->getId());
		$oTopic->setDateEdit(date("Y-m-d H:i:s"));
		if ($this->oMapperTopic->UpdateTopic($oTopic)) {	
			/**
			 * Если топик изменил видимость(publish)
			 */
			if ($oTopic->getPublish()!=$oTopicOld->getPublish()) {
				/**
				 * Обновляем теги
				 */
				$aTags=explode(',',$oTopic->getTags());
				$this->DeleteTopicTagsByTopicId($oTopic->getId());
				if ($oTopic->getPublish()) {
					foreach ($aTags as $sTag) {
						$oTag=Engine::GetEntity('Topic_TopicTag');
						$oTag->setTopicId($oTopic->getId());
						$oTag->setUserId($oTopic->getUserId());
						$oTag->setBlogId($oTopic->getBlogId());
						$oTag->setText($sTag);
						$this->oMapperTopic->AddTopicTag($oTag);
					}
				}
				/**
			 	* Обновляем избранное
			 	*/
				$this->SetFavouriteTopicPublish($oTopic->getId(),$oTopic->getPublish());
				/**
			 	* Удаляем комментарий топика из прямого эфира
			 	*/
				if ($oTopic->getPublish()==0) {
					$this->Comment_DeleteCommentOnlineByTargetId($oTopic->getId(),'topic');
				}
				/**
				 * Изменяем видимость комментов
				 */
				$this->Comment_SetCommentsPublish($oTopic->getId(),'topic',$oTopic->getPublish());
			}
			//чистим зависимые кеши			
			$this->Cache_Clean(Zend_Cache::CLEANING_MODE_MATCHING_TAG,array('topic_update',"topic_update_user_{$oTopic->getUserId()}","topic_update_blog_{$oTopic->getBlogId()}"));
			$this->Cache_Delete("topic_{$oTopic->getId()}");
			return true;
		}
		return false;
	}	
		
	/**
	 * Получить топик по айдишнику
	 *
	 * @param unknown_type $sId
	 * @return unknown
	 */
	public function GetTopicById($sId) {		
		$aTopics=$this->GetTopicsAdditionalData($sId);
		if (isset($aTopics[$sId])) {
			return $aTopics[$sId];
		}
		return null;
	}	
	/**
	 * Получить список топиков по списку айдишников
	 *
	 * @param unknown_type $aTopicId
	 */
	public function GetTopicsByArrayId($aTopicId) {
		if (!$aTopicId) {
			return array();
		}
		if (1) {
			return $this->GetTopicsByArrayIdSolid($aTopicId);
		}
		
		if (!is_array($aTopicId)) {
			$aTopicId=array($aTopicId);
		}
		$aTopicId=array_unique($aTopicId);
		$aTopics=array();
		$aTopicIdNotNeedQuery=array();
		/**
		 * Делаем мульти-запрос к кешу
		 */
		$aCacheKeys=func_build_cache_keys($aTopicId,'topic_');
		if (false !== ($data = $this->Cache_Get($aCacheKeys))) {			
			/**
			 * проверяем что досталось из кеша
			 */
			foreach ($aCacheKeys as $sValue => $sKey ) {
				if (array_key_exists($sKey,$data)) {	
					if ($data[$sKey]) {
						$aTopics[$data[$sKey]->getId()]=$data[$sKey];
					} else {
						$aTopicIdNotNeedQuery[]=$sValue;
					}
				} 
			}
		}
		/**
		 * Смотрим каких топиков не было в кеше и делаем запрос в БД
		 */		
		$aTopicIdNeedQuery=array_diff($aTopicId,array_keys($aTopics));		
		$aTopicIdNeedQuery=array_diff($aTopicIdNeedQuery,$aTopicIdNotNeedQuery);		
		$aTopicIdNeedStore=$aTopicIdNeedQuery;
		if ($data = $this->oMapperTopic->GetTopicsByArrayId($aTopicIdNeedQuery)) {
			foreach ($data as $oTopic) {
				/**
				 * Добавляем к результату и сохраняем в кеш
				 */
				$aTopics[$oTopic->getId()]=$oTopic;
				$this->Cache_Set($oTopic, "topic_{$oTopic->getId()}", array(), 60*60*24*4);
				$aTopicIdNeedStore=array_diff($aTopicIdNeedStore,array($oTopic->getId()));
			}
		}
		/**
		 * Сохраняем в кеш запросы не вернувшие результата
		 */
		foreach ($aTopicIdNeedStore as $sId) {
			$this->Cache_Set(null, "topic_{$sId}", array(), 60*60*24*4);
		}	
		/**
		 * Сортируем результат согласно входящему массиву
		 */
		$aTopics=func_array_sort_by_keys($aTopics,$aTopicId);
		return $aTopics;		
	}
	/**
	 * Получить список топиков по списку айдишников, но используя единый кеш
	 *
	 * @param unknown_type $aTopicId
	 * @return unknown
	 */
	public function GetTopicsByArrayIdSolid($aTopicId) {
		if (!is_array($aTopicId)) {
			$aTopicId=array($aTopicId);
		}
		$aTopicId=array_unique($aTopicId);	
		$aTopics=array();	
		$s=join(',',$aTopicId);
		if (false === ($data = $this->Cache_Get("topic_id_{$s}"))) {			
			$data = $this->oMapperTopic->GetTopicsByArrayId($aTopicId);
			foreach ($data as $oTopic) {
				$aTopics[$oTopic->getId()]=$oTopic;
			}
			$this->Cache_Set($aTopics, "topic_id_{$s}", array("topic_update"), 60*60*24*1);
			return $aTopics;
		}		
		return $data;
	}
	/**
	 * Получает список топиков из избранного
	 *
	 * @param  string $sUserId
	 * @param  int    $iCount
	 * @param  int    $iCurrPage
	 * @param  int    $iPerPage
	 * @return array
	 */
	public function GetTopicsFavouriteByUserId($sUserId,$iCurrPage,$iPerPage) {		
		// Получаем список идентификаторов избранных записей
		$data = $this->Favourite_GetFavouritesByUserId($sUserId,'topic',$iCurrPage,$iPerPage);
		// Получаем записи по переданому массиву айдишников
		$data['collection']=$this->GetTopicsAdditionalData($data['collection']);		
		return $data;		
	}
	/**
	 * Возвращает число топиков в избранном
	 *
	 * @param  string $sUserId
	 * @return int
	 */
	public function GetCountTopicsFavouriteByUserId($sUserId) {
		return $this->Favourite_GetCountFavouritesByUserId($sUserId,'topic');	
	}
	/**
	 * Список топиков по фильтру
	 *
	 * @param unknown_type $aFilter
	 * @param unknown_type $iPage
	 * @param unknown_type $iPerPage
	 * @return unknown
	 */
	protected function GetTopicsByFilter($aFilter,$iPage,$iPerPage) {
		$s=serialize($aFilter);
		if (false === ($data = $this->Cache_Get("topic_filter_{$s}_{$iPage}_{$iPerPage}"))) {			
			$data = array('collection'=>$this->oMapperTopic->GetTopics($aFilter,$iCount,$iPage,$iPerPage),'count'=>$iCount);
			$this->Cache_Set($data, "topic_filter_{$s}_{$iPage}_{$iPerPage}", array('topic_update','topic_new'), 60*60*24*3);
		}
		$data['collection']=$this->GetTopicsAdditionalData($data['collection']);
		return $data;		
	}
	/**
	 * Количество топиков по фильтру
	 *
	 * @param unknown_type $aFilter
	 * @return unknown
	 */
	protected function GetCountTopicsByFilter($aFilter) {
		$s=serialize($aFilter);					
		if (false === ($data = $this->Cache_Get("topic_count_{$s}"))) {			
			$data = $this->oMapperTopic->GetCountTopics($aFilter);
			$this->Cache_Set($data, "topic_count_{$s}", array('topic_update','topic_new'), 60*60*24*1);
		}
		return 	$data;
	}
	/**
	 * Получает список хороших топиков для вывода на главную страницу(из всех блогов, как коллективных так и персональных)
	 *
	 * @param unknown_type $iPage
	 * @param unknown_type $iPerPage
	 * @return unknown
	 */
	public function GetTopicsGood($iPage,$iPerPage) {
		$aFilter=array(
			'blog_type' => array(
				'personal',
				'open',
			),
			'topic_publish' => 1,
			'topic_rating'  => array(
				'value' => Config::Get('module.blog.index_good'),
				'type'  => 'top',
				'publish_index'  => 1,
			),
		);			
		return $this->GetTopicsByFilter($aFilter,$iPage,$iPerPage);
	}
	/**
	 * Получает список ВСЕХ новых топиков
	 *
	 * @param unknown_type $iPage
	 * @param unknown_type $iPerPage
	 * @return unknown
	 */
	public function GetTopicsNew($iPage,$iPerPage) {
		$sDate=date("Y-m-d H:00:00",time()-Config::Get('module.topic.new_time'));
		$aFilter=array(
			'blog_type' => array(
				'personal',
				'open',
			),
			'topic_publish' => 1,
			'topic_new' => $sDate,
		);		
		return $this->GetTopicsByFilter($aFilter,$iPage,$iPerPage);
	}
	/**
	 * Получает заданое число последних топиков
	 *
	 * @param unknown_type $iCount
	 * @return unknown
	 */
	public function GetTopicsLast($iCount) {		
		$aFilter=array(
			'blog_type' => array(
				'personal',
				'open',
			),
			'topic_publish' => 1,			
		);			
		$aReturn=$this->GetTopicsByFilter($aFilter,1,$iCount);
		if (isset($aReturn['collection'])) {
			return $aReturn['collection'];
		}
		return false;
	}
	/**
	 * список топиков из персональных блогов
	 *
	 * @param unknown_type $iPage
	 * @param unknown_type $iPerPage
	 * @param unknown_type $sShowType
	 * @return unknown
	 */
	public function GetTopicsPersonal($iPage,$iPerPage,$sShowType='good') {
		$aFilter=array(
			'blog_type' => array(
				'personal',
			),
			'topic_publish' => 1,			
		);
		switch ($sShowType) {
			case 'good':
				$aFilter['topic_rating']=array(
					'value' => Config::Get('module.blog.personal_good'),
					'type'  => 'top',
				);			
				break;	
			case 'bad':
				$aFilter['topic_rating']=array(
					'value' => Config::Get('module.blog.personal_good'),
					'type'  => 'down',
				);			
				break;	
			case 'new':
				$aFilter['topic_new']=date("Y-m-d H:00:00",time()-Config::Get('module.topic.new_time'));							
				break;
			default:
				break;
		}
		return $this->GetTopicsByFilter($aFilter,$iPage,$iPerPage);
	}	
	/**
	 * Получает число новых топиков в персональных блогах
	 *
	 * @return unknown
	 */
	public function GetCountTopicsPersonalNew() {
		$sDate=date("Y-m-d H:00:00",time()-Config::Get('module.topic.new_time'));
		$aFilter=array(
			'blog_type' => array(
				'personal',
			),
			'topic_publish' => 1,
			'topic_new' => $sDate,
		);				
		return $this->GetCountTopicsByFilter($aFilter);
	}
	/**
	 * Получает список топиков по юзеру
	 *
	 * @param unknown_type $sUserId
	 * @param unknown_type $iPublish
	 * @param unknown_type $iPage
	 * @param unknown_type $iPerPage
	 * @return unknown
	 */
	public function GetTopicsPersonalByUser($sUserId,$iPublish,$iPage,$iPerPage) {
		$aFilter=array(			
			'topic_publish' => $iPublish,
			'user_id' => $sUserId,			
		);
		return $this->GetTopicsByFilter($aFilter,$iPage,$iPerPage);
	}
	/**
	 * Возвращает количество топиков которые создал юзер
	 *
	 * @param unknown_type $sUserId
	 * @param unknown_type $iPublish
	 * @return unknown
	 */
	public function GetCountTopicsPersonalByUser($sUserId,$iPublish) {
		$aFilter=array(			
			'topic_publish' => $iPublish,
			'user_id' => $sUserId,			
		);
		$s=serialize($aFilter);					
		if (false === ($data = $this->Cache_Get("topic_count_user_{$s}"))) {			
			$data = $this->oMapperTopic->GetCountTopics($aFilter);
			$this->Cache_Set($data, "topic_count_user_{$s}", array("topic_update_user_{$sUserId}","topic_new_user_{$sUserId}"), 60*60*24);
		}
		return 	$data;		
	}
	/**
	 * список топиков из коллективных блогов
	 *
	 * @param unknown_type $iPage
	 * @param unknown_type $iPerPage
	 * @param unknown_type $sShowType
	 * @return unknown
	 */
	public function GetTopicsCollective($iPage,$iPerPage,$sShowType='good') {
		$aFilter=array(
			'blog_type' => array(
				'open',
			),
			'topic_publish' => 1,			
		);
		switch ($sShowType) {
			case 'good':
				$aFilter['topic_rating']=array(
					'value' => Config::Get('module.blog.collective_good'),
					'type'  => 'top',
				);			
				break;	
			case 'bad':
				$aFilter['topic_rating']=array(
					'value' => Config::Get('module.blog.collective_good'),
					'type'  => 'down',
				);			
				break;	
			case 'new':
				$aFilter['topic_new']=date("Y-m-d H:00:00",time()-Config::Get('module.topic.new_time'));							
				break;
			default:
				break;
		}
		return $this->GetTopicsByFilter($aFilter,$iPage,$iPerPage);
	}	
	/**
	 * Получает число новых топиков в коллективных блогах
	 *
	 * @return unknown
	 */
	public function GetCountTopicsCollectiveNew() {
		$sDate=date("Y-m-d H:00:00",time()-Config::Get('module.topic.new_time'));
		$aFilter=array(
			'blog_type' => array(
				'open',
			),
			'topic_publish' => 1,
			'topic_new' => $sDate,
		);
		return $this->GetCountTopicsByFilter($aFilter);		
	}
	/**
	 * Получает топики по рейтингу и дате
	 *
	 * @param unknown_type $sDate
	 * @param unknown_type $iLimit
	 * @return unknown
	 */
	public function GetTopicsRatingByDate($sDate,$iLimit=20) {
		if (false === ($data = $this->Cache_Get("topic_rating_{$sDate}_{$iLimit}"))) {
			$data = $this->oMapperTopic->GetTopicsRatingByDate($sDate,$iLimit);
			$this->Cache_Set($data, "topic_rating_{$sDate}_{$iLimit}", array('topic_update'), 60*60*24*2);
		}
		$data=$this->GetTopicsAdditionalData($data);
		return $data;		
	}	
	/**
	 * Список топиков из блога
	 *
	 * @param unknown_type $oBlog
	 * @param unknown_type $iPage
	 * @param unknown_type $iPerPage
	 * @param unknown_type $sShowType
	 * @return unknown
	 */
	public function GetTopicsByBlog($oBlog,$iPage,$iPerPage,$sShowType='good') {
		$aFilter=array(
			'blog_type' => array(
				'open',
			),
			'topic_publish' => 1,
			'blog_id' => $oBlog->getId(),
		);
		switch ($sShowType) {
			case 'good':
				$aFilter['topic_rating']=array(
					'value' => Config::Get('module.blog.collective_good'),
					'type'  => 'top',
				);			
				break;	
			case 'bad':
				$aFilter['topic_rating']=array(
					'value' => Config::Get('module.blog.collective_good'),
					'type'  => 'down',
				);			
				break;	
			case 'new':
				$aFilter['topic_new']=date("Y-m-d H:00:00",time()-Config::Get('module.topic.new_time'));							
				break;
			default:
				break;
		}
		return $this->GetTopicsByFilter($aFilter,$iPage,$iPerPage);
	}
	
	/**
	 * Получает число новых топиков из блога
	 *
	 * @param unknown_type $oBlog
	 * @return unknown
	 */
	public function GetCountTopicsByBlogNew($oBlog) {
		$sDate=date("Y-m-d H:00:00",time()-Config::Get('module.topic.new_time'));
		$aFilter=array(
			'blog_type' => array(
				'open',
			),
			'topic_publish' => 1,
			'blog_id' => $oBlog->getId(),
			'topic_new' => $sDate,
			
		);
		return $this->GetCountTopicsByFilter($aFilter);		
	}
	/**
	 * Получает список топиков по тегу
	 *
	 * @param unknown_type $sTag
	 * @param unknown_type $iPage
	 * @param unknown_type $iPerPage
	 * @return unknown
	 */
	public function GetTopicsByTag($sTag,$iPage,$iPerPage) {		
		if (false === ($data = $this->Cache_Get("topic_tag_{$sTag}_{$iPage}_{$iPerPage}"))) {			
			$data = array('collection'=>$this->oMapperTopic->GetTopicsByTag($sTag,$iCount,$iPage,$iPerPage),'count'=>$iCount);
			$this->Cache_Set($data, "topic_tag_{$sTag}_{$iPage}_{$iPerPage}", array('topic_update','topic_new'), 60*60*24*2);
		}
		$data['collection']=$this->GetTopicsAdditionalData($data['collection']);
		return $data;		
	}
	/**
	 * Получает список тегов топиков
	 *
	 * @param unknown_type $iLimit
	 * @return unknown
	 */
	public function GetTopicTags($iLimit) {
		if (false === ($data = $this->Cache_Get("tag_{$iLimit}"))) {			
			$data = $this->oMapperTopic->GetTopicTags($iLimit);
			$this->Cache_Set($data, "tag_{$iLimit}", array('topic_update','topic_new'), 60*60*24*3);
		}
		return $data;		
	}
	
	/**
	 * Увеличивает у топика число комментов
	 *
	 * @param unknown_type $sTopicId
	 * @return unknown
	 */
	public function increaseTopicCountComment($sTopicId) {		
		$this->Cache_Delete("topic_{$sTopicId}");
		$this->Cache_Clean(Zend_Cache::CLEANING_MODE_MATCHING_TAG,array("topic_update"));
		return $this->oMapperTopic->increaseTopicCountComment($sTopicId);
	}
	/**
	 * Получает привязку топика к ибранному(добавлен ли топик в избранное у юзера)
	 *
	 * @param unknown_type $sTopicId
	 * @param unknown_type $sUserId
	 * @return unknown
	 */
	public function GetFavouriteTopic($sTopicId,$sUserId) {
		return $this->Favourite_GetFavourite($sTopicId,'topic',$sUserId);
	}
	/**
	 * Получить список избранного по списку айдишников
	 *
	 * @param unknown_type $aTopicId
	 */
	public function GetFavouriteTopicsByArray($aTopicId,$sUserId) {
		return $this->Favourite_GetFavouritesByArray($aTopicId,'topic',$sUserId);
	}
	/**
	 * Получить список избранного по списку айдишников, но используя единый кеш
	 *
	 * @param array $aTopicId
	 * @param int $sUserId
	 * @return array
	 */
	public function GetFavouriteTopicsByArraySolid($aTopicId,$sUserId) {
		return $this->Favourite_GetFavouritesByArraySolid($aTopicId,'topic',$sUserId);
	}
	/**
	 * Добавляет топик в избранное
	 *
	 * @param FavouriteEntity_Favourite $oFavouriteTopic
	 * @return unknown
	 */
	public function AddFavouriteTopic(FavouriteEntity_Favourite $oFavouriteTopic) {		
		return $this->Favourite_AddFavourite($oFavouriteTopic);
		
	}
	/**
	 * Удаляет топик из избранного
	 *
	 * @param FavouriteEntity_Favourite $oFavouriteTopic
	 * @return unknown
	 */
	public function DeleteFavouriteTopic(FavouriteEntity_Favourite $oFavouriteTopic) {	
		return $this->Favourite_DeleteFavourite($oFavouriteTopic);
	}
	/**
	 * Устанавливает переданный параметр публикации таргета (топика)
	 *
	 * @param  string $sTopicId
	 * @param  int    $iPublish
	 * @return bool
	 */
	public function SetFavouriteTopicPublish($sTopicId,$iPublish) {
		return $this->Favourite_SetFavouriteTargetPublish($sTopicId,'topic',$iPublish);		
	}
	/**
	 * Получает список тегов по первым буквам тега
	 *
	 * @param unknown_type $sTag
	 * @param unknown_type $iLimit
	 */
	public function GetTopicTagsByLike($sTag,$iLimit) {
		if (false === ($data = $this->Cache_Get("tag_like_{$sTag}_{$iLimit}"))) {			
			$data = $this->oMapperTopic->GetTopicTagsByLike($sTag,$iLimit);
			$this->Cache_Set($data, "tag_like_{$sTag}_{$iLimit}", array("topic_update","topic_new"), 60*60*24*3);
		}
		return $data;		
	}
	/**
	 * Обновляем/устанавливаем дату прочтения топика, если читаем его первый раз то добавляем
	 *
	 * @param TopicEntity_TopicRead $oTopicRead	 
	 */
	public function SetTopicRead(TopicEntity_TopicRead $oTopicRead) {		
		if ($this->GetTopicRead($oTopicRead->getTopicId(),$oTopicRead->getUserId())) {
			$this->Cache_Delete("topic_read_{$oTopicRead->getTopicId()}_{$oTopicRead->getUserId()}");
			$this->Cache_Clean(Zend_Cache::CLEANING_MODE_MATCHING_TAG,array("topic_read_user_{$oTopicRead->getUserId()}"));
			$this->oMapperTopic->UpdateTopicRead($oTopicRead);
		} else {
			$this->Cache_Delete("topic_read_{$oTopicRead->getTopicId()}_{$oTopicRead->getUserId()}");
			$this->Cache_Clean(Zend_Cache::CLEANING_MODE_MATCHING_TAG,array("topic_read_user_{$oTopicRead->getUserId()}"));
			$this->oMapperTopic->AddTopicRead($oTopicRead);
		}
		return true;		
	}	
	/**
	 * Получаем дату прочтения топика юзером
	 *
	 * @param unknown_type $sTopicId
	 * @param unknown_type $sUserId
	 * @return unknown
	 */
	public function GetTopicRead($sTopicId,$sUserId) {
		$data=$this->GetTopicsReadByArray($sTopicId,$sUserId);
		if (isset($data[$sTopicId])) {
			return $data[$sTopicId];
		}
		return null;
	}	
	/**
	 * Получить список просмотром/чтения топиков по списку айдишников
	 *
	 * @param unknown_type $aTopicId
	 */
	public function GetTopicsReadByArray($aTopicId,$sUserId) {
		if (!$aTopicId) {
			return array();
		}
		if (1) {
			return $this->GetTopicsReadByArraySolid($aTopicId,$sUserId);
		}
		if (!is_array($aTopicId)) {
			$aTopicId=array($aTopicId);
		}
		$aTopicId=array_unique($aTopicId);
		$aTopicsRead=array();
		$aTopicIdNotNeedQuery=array();
		/**
		 * Делаем мульти-запрос к кешу
		 */
		$aCacheKeys=func_build_cache_keys($aTopicId,'topic_read_','_'.$sUserId);
		if (false !== ($data = $this->Cache_Get($aCacheKeys))) {			
			/**
			 * проверяем что досталось из кеша
			 */
			foreach ($aCacheKeys as $sValue => $sKey ) {
				if (array_key_exists($sKey,$data)) {	
					if ($data[$sKey]) {
						$aTopicsRead[$data[$sKey]->getTopicId()]=$data[$sKey];
					} else {
						$aTopicIdNotNeedQuery[]=$sValue;
					}
				} 
			}
		}
		/**
		 * Смотрим каких топиков не было в кеше и делаем запрос в БД
		 */		
		$aTopicIdNeedQuery=array_diff($aTopicId,array_keys($aTopicsRead));		
		$aTopicIdNeedQuery=array_diff($aTopicIdNeedQuery,$aTopicIdNotNeedQuery);		
		$aTopicIdNeedStore=$aTopicIdNeedQuery;
		if ($data = $this->oMapperTopic->GetTopicsReadByArray($aTopicIdNeedQuery,$sUserId)) {
			foreach ($data as $oTopicRead) {
				/**
				 * Добавляем к результату и сохраняем в кеш
				 */
				$aTopicsRead[$oTopicRead->getTopicId()]=$oTopicRead;
				$this->Cache_Set($oTopicRead, "topic_read_{$oTopicRead->getTopicId()}_{$oTopicRead->getUserId()}", array(), 60*60*24*4);
				$aTopicIdNeedStore=array_diff($aTopicIdNeedStore,array($oTopicRead->getTopicId()));
			}
		}
		/**
		 * Сохраняем в кеш запросы не вернувшие результата
		 */
		foreach ($aTopicIdNeedStore as $sId) {
			$this->Cache_Set(null, "topic_read_{$sId}_{$sUserId}", array(), 60*60*24*4);
		}		
		/**
		 * Сортируем результат согласно входящему массиву
		 */
		$aTopicsRead=func_array_sort_by_keys($aTopicsRead,$aTopicId);
		return $aTopicsRead;		
	}
	/**
	 * Получить список просмотром/чтения топиков по списку айдишников, но используя единый кеш
	 *
	 * @param unknown_type $aTopicId
	 * @param unknown_type $sUserId
	 * @return unknown
	 */
	public function GetTopicsReadByArraySolid($aTopicId,$sUserId) {
		if (!is_array($aTopicId)) {
			$aTopicId=array($aTopicId);
		}
		$aTopicId=array_unique($aTopicId);	
		$aTopicsRead=array();	
		$s=join(',',$aTopicId);
		if (false === ($data = $this->Cache_Get("topic_read_{$sUserId}_id_{$s}"))) {			
			$data = $this->oMapperTopic->GetTopicsReadByArray($aTopicId,$sUserId);
			foreach ($data as $oTopicRead) {
				$aTopicsRead[$oTopicRead->getTopicId()]=$oTopicRead;
			}
			$this->Cache_Set($aTopicsRead, "topic_read_{$sUserId}_id_{$s}", array("topic_read_user_{$sUserId}"), 60*60*24*1);
			return $aTopicsRead;
		}		
		return $data;
	}
	/**
	 * Проверяет голосовал ли юзер за топик-вопрос
	 *
	 * @param unknown_type $sTopicId
	 * @param unknown_type $sUserId
	 * @return unknown
	 */
	public function GetTopicQuestionVote($sTopicId,$sUserId) {
		$data=$this->GetTopicsQuestionVoteByArray($sTopicId,$sUserId);
		if (isset($data[$sTopicId])) {
			return $data[$sTopicId];
		}
		return null;
	}
	/**
	 * Получить список голосований в топике-опросе по списку айдишников
	 *
	 * @param unknown_type $aTopicId
	 */
	public function GetTopicsQuestionVoteByArray($aTopicId,$sUserId) {
		if (!$aTopicId) {
			return array();
		}
		if (1) {
			return $this->GetTopicsQuestionVoteByArraySolid($aTopicId,$sUserId);
		}
		if (!is_array($aTopicId)) {
			$aTopicId=array($aTopicId);
		}
		$aTopicId=array_unique($aTopicId);
		$aTopicsQuestionVote=array();
		$aTopicIdNotNeedQuery=array();
		/**
		 * Делаем мульти-запрос к кешу
		 */
		$aCacheKeys=func_build_cache_keys($aTopicId,'topic_question_vote_','_'.$sUserId);
		if (false !== ($data = $this->Cache_Get($aCacheKeys))) {			
			/**
			 * проверяем что досталось из кеша
			 */
			foreach ($aCacheKeys as $sValue => $sKey ) {
				if (array_key_exists($sKey,$data)) {	
					if ($data[$sKey]) {
						$aTopicsQuestionVote[$data[$sKey]->getTopicId()]=$data[$sKey];
					} else {
						$aTopicIdNotNeedQuery[]=$sValue;
					}
				} 
			}
		}
		/**
		 * Смотрим каких топиков не было в кеше и делаем запрос в БД
		 */		
		$aTopicIdNeedQuery=array_diff($aTopicId,array_keys($aTopicsQuestionVote));		
		$aTopicIdNeedQuery=array_diff($aTopicIdNeedQuery,$aTopicIdNotNeedQuery);		
		$aTopicIdNeedStore=$aTopicIdNeedQuery;
		if ($data = $this->oMapperTopic->GetTopicsQuestionVoteByArray($aTopicIdNeedQuery,$sUserId)) {
			foreach ($data as $oTopicVote) {
				/**
				 * Добавляем к результату и сохраняем в кеш
				 */
				$aTopicsQuestionVote[$oTopicVote->getTopicId()]=$oTopicVote;
				$this->Cache_Set($oTopicVote, "topic_question_vote_{$oTopicVote->getTopicId()}_{$oTopicVote->getVoterId()}", array(), 60*60*24*4);
				$aTopicIdNeedStore=array_diff($aTopicIdNeedStore,array($oTopicVote->getTopicId()));
			}
		}
		/**
		 * Сохраняем в кеш запросы не вернувшие результата
		 */
		foreach ($aTopicIdNeedStore as $sId) {
			$this->Cache_Set(null, "topic_question_vote_{$sId}_{$sUserId}", array(), 60*60*24*4);
		}		
		/**
		 * Сортируем результат согласно входящему массиву
		 */
		$aTopicsQuestionVote=func_array_sort_by_keys($aTopicsQuestionVote,$aTopicId);
		return $aTopicsQuestionVote;		
	}
	/**
	 * Получить список голосований в топике-опросе по списку айдишников, но используя единый кеш
	 *
	 * @param unknown_type $aTopicId
	 * @param unknown_type $sUserId
	 * @return unknown
	 */
	public function GetTopicsQuestionVoteByArraySolid($aTopicId,$sUserId) {
		if (!is_array($aTopicId)) {
			$aTopicId=array($aTopicId);
		}
		$aTopicId=array_unique($aTopicId);	
		$aTopicsQuestionVote=array();	
		$s=join(',',$aTopicId);
		if (false === ($data = $this->Cache_Get("topic_question_vote_{$sUserId}_id_{$s}"))) {			
			$data = $this->oMapperTopic->GetTopicsQuestionVoteByArray($aTopicId,$sUserId);
			foreach ($data as $oTopicVote) {
				$aTopicsQuestionVote[$oTopicVote->getTopicId()]=$oTopicVote;
			}
			$this->Cache_Set($aTopicsQuestionVote, "topic_question_vote_{$sUserId}_id_{$s}", array("topic_question_vote_user_{$sUserId}"), 60*60*24*1);
			return $aTopicsQuestionVote;
		}		
		return $data;
	}
	/**
	 * Добавляет факт голосования за топик-вопрос
	 *
	 * @param TopicEntity_TopicQuestionVote $oTopicQuestionVote
	 */
	public function AddTopicQuestionVote(TopicEntity_TopicQuestionVote $oTopicQuestionVote) {
		$this->Cache_Delete("topic_question_vote_{$oTopicQuestionVote->getTopicId()}_{$oTopicQuestionVote->getVoterId()}");
		$this->Cache_Clean(Zend_Cache::CLEANING_MODE_MATCHING_TAG,array("topic_question_vote_user_{$oTopicQuestionVote->getVoterId()}"));
		return $this->oMapperTopic->AddTopicQuestionVote($oTopicQuestionVote);
	}
	/**
	 * Получает топик по уникальному хешу(текст топика)
	 *
	 * @param unknown_type $sUserId
	 * @param unknown_type $sHash
	 * @return unknown
	 */
	public function GetTopicUnique($sUserId,$sHash) {
		$sId=$this->oMapperTopic->GetTopicUnique($sUserId,$sHash);
		return $this->GetTopicById($sId);
	}
	/**
	 * Проверяет можно или нет пользователю редактировать данный топик
	 *
	 * @param unknown_type $oTopic
	 * @param unknown_type $oUser
	 */
	public function IsAllowEditTopic($oTopic,$oUser) {
		/**
		 * Разрешаем если это админ сайта или автор топика
		 */
		if ($oTopic->getUserId()==$oUser->getId() or $oUser->isAdministrator()) {
			return true;
		}
		/**
		 * Если автор(смотритель) блога
		 */
		if ($oTopic->getBlog()->getOwnerId()==$oUser->getId()) {
			return true;
		}
		/**
		 * Если модер или админ блога
		 */
		$oBlogUser=$this->Blog_GetBlogUserByBlogIdAndUserId($oTopic->getBlogId(),$oUser->getId());
		if ($oBlogUser and ($oBlogUser->getIsModerator() or $oBlogUser->getIsAdministrator())) {
			return true;
		}		
		return false;
	}
	/**
	 * Проверяет можно или нет пользователю удалять данный топик
	 *
	 * @param unknown_type $oTopic
	 * @param unknown_type $oUser
	 */
	public function IsAllowDeleteTopic($oTopic,$oUser) {
		$bReturn=false;
		/**
		 * Разрешаем если это админ сайта или автор топика
		 */
		if ($oUser->isAdministrator()) {
			$bReturn=true;
		}				
		return $bReturn;
	}
	/**
	 * Рассылает уведомления о новом топике подписчикам блога
	 *
	 * @param unknown_type $oBlog
	 * @param unknown_type $oTopic
	 * @param unknown_type $oUserTopic
	 */
	public function SendNotifyTopicNew($oBlog,$oTopic,$oUserTopic) {
		$aBlogUsers=$this->Blog_GetBlogUsersByBlogId($oBlog->getId());
		foreach ($aBlogUsers as $oBlogUser) {
			if ($oBlogUser->getUserId()==$oUserTopic->getId()) {
				continue;
			}
			$this->Notify_SendTopicNewToSubscribeBlog($oBlogUser->getUser(),$oTopic,$oBlog,$oUserTopic);
		}
		//отправляем создателю блога
		if ($oBlog->getOwnerId()!=$oUserTopic->getId()) {
			$this->Notify_SendTopicNewToSubscribeBlog($oBlog->getOwner(),$oTopic,$oBlog,$oUserTopic);
		}	
	}
}
?>