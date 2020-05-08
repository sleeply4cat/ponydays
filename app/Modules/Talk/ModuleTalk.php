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

namespace App\Modules\Talk;

use App\Modules\Comment\ModuleComment;
use App\Modules\Favourite\Entity\ModuleFavourite_EntityFavourite;
use App\Modules\Favourite\ModuleFavourite;
use App\Modules\Notify\ModuleNotify;
use App\Modules\Talk\Entity\ModuleTalk_EntityTalk;
use App\Modules\Talk\Entity\ModuleTalk_EntityTalkUser;
use App\Modules\Talk\Mapper\ModuleTalk_MapperTalk;
use App\Modules\User\Entity\ModuleUser_EntityUser;
use App\Modules\User\ModuleUser;
use Engine\Config;
use Engine\Engine;
use Engine\LS;
use Engine\Module;
use Engine\Modules\Cache\ModuleCache;
use Zend_Cache;

/**
 * Модуль разговоров(почта)
 *
 * @package modules.talk
 * @since 1.0
 */
class ModuleTalk extends Module {
	/**
	 * Статус TalkUser в базе данных
	 * Пользователь активен в разговоре
	 */
	const TALK_USER_ACTIVE = 1;
	/**
	 * Пользователь удалил разговор
	 */
	const TALK_USER_DELETE_BY_SELF = 2;
	/**
	 * Пользователь приглашен в разговор обратно
	 */
	const TALK_USER_INVITED_BACK = 3;
	/**
	 * Пользователя удалил из разговора автор письма
	 */
	const TALK_USER_DELETE_BY_AUTHOR = 4;

	/**
	 * Объект маппера
	 *
	 * @var ModuleTalk_MapperTalk
	 */
	protected $oMapper;
	/**
	 * Объект текущего пользователя
	 *
	 * @var ModuleUser_EntityUser|null
	 */
	protected $oUserCurrent=null;

	/**
	 * Инициализация
	 *
	 */
	public function Init() {
		$this->oMapper=Engine::MakeMapper(ModuleTalk_MapperTalk::class);
		$this->oUserCurrent=LS::Make(ModuleUser::class)->GetUserCurrent();
	}
	/**
	 * Формирует и отправляет личное сообщение
	 *
	 * @param string $sTitle	Заголовок сообщения
	 * @param string $sText	Текст сообщения
	 * @param int|ModuleUser_EntityUser $oUserFrom	Пользователь от которого отправляем
	 * @param array|int|ModuleUser_EntityUser $aUserTo	Пользователь которому отправляем
	 * @param bool $bSendNotify	Отправлять или нет уведомление на емайл
	 * @param bool $bUseBlacklist	Исклюать или нет пользователей из блэклиста
	 * @return ModuleTalk_EntityTalk|bool
	 */
	public function SendTalk($sTitle,$sText,$oUserFrom,$aUserTo,$bSendNotify=true,$bUseBlacklist=true) {
		$iUserIdFrom=$oUserFrom instanceof ModuleUser_EntityUser ? $oUserFrom->getId() : (int)$oUserFrom;
		if (!is_array($aUserTo)) {
			$aUserTo=array($aUserTo);
		}
		$aUserIdTo=array($iUserIdFrom);
		if($bUseBlacklist) {
			$aUserInBlacklist=$this->GetBlacklistByTargetId($iUserIdFrom);
		}

		foreach ($aUserTo as $oUserTo) {
			$sUserIdTo=$oUserTo instanceof ModuleUser_EntityUser ? $oUserTo->getId() : (int)$oUserTo;
			if(!$bUseBlacklist || !in_array($sUserIdTo,$aUserInBlacklist)) {
				$aUserIdTo[]=$sUserIdTo;
			}
		}
		$aUserIdTo=array_unique($aUserIdTo);
		if(!empty($aUserIdTo)) {
			$oTalk = new ModuleTalk_EntityTalk();
			$oTalk->setUserId($iUserIdFrom);
			$oTalk->setTitle($sTitle);
			$oTalk->setText($sText);
			$oTalk->setDate(date("Y-m-d H:i:s"));
			$oTalk->setDateLast(date("Y-m-d H:i:s"));
			$oTalk->setUserIdLast($oTalk->getUserId());
			$oTalk->setUserIp(func_getIp());
			if ($oTalk=$this->AddTalk($oTalk)) {
				foreach ($aUserIdTo as $iUserId) {
					$oTalkUser = new ModuleTalk_EntityTalkUser();
					$oTalkUser->setTalkId($oTalk->getId());
					$oTalkUser->setUserId($iUserId);
					if ($iUserId==$iUserIdFrom) {
						$oTalkUser->setDateLast(date("Y-m-d H:i:s"));
					} else {
						$oTalkUser->setDateLast(null);
					}
					$this->AddTalkUser($oTalkUser);

					if ($bSendNotify) {
						if ($iUserId!=$iUserIdFrom) {
						    $user = LS::Make(ModuleUser::class);
							$oUserFrom=$user->GetUserById($iUserIdFrom);
							$oUserToMail=$user->GetUserById($iUserId);
							LS::Make(ModuleNotify::class)->SendTalkNew($oUserToMail,$oUserFrom,$oTalk);
						}
					}
				}
				return $oTalk;
			}
		}
		return false;
	}
	/**
	 * Добавляет новую тему разговора
	 *
	 * @param ModuleTalk_EntityTalk $oTalk Объект сообщения
	 * @return ModuleTalk_EntityTalk|bool
	 */
	public function AddTalk(ModuleTalk_EntityTalk $oTalk) {
		if ($sId=$this->oMapper->AddTalk($oTalk)) {
			$oTalk->setId($sId);
			//чистим зависимые кеши
            /** @var ModuleCache $cache */
            $cache = LS::Make(ModuleCache::class);
			$cache->Clean(Zend_Cache::CLEANING_MODE_MATCHING_TAG,array('talk_new',"talk_new_user_{$oTalk->getUserId()}"));
			return $oTalk;
		}
		return false;
	}
	/**
	 * Обновление разговора
	 *
	 * @param ModuleTalk_EntityTalk $oTalk	Объект сообщения
	 * @return int
	 */
	public function UpdateTalk(ModuleTalk_EntityTalk $oTalk) {
        /** @var ModuleCache $cache */
        $cache = LS::Make(ModuleCache::class);
		$cache->Delete("talk_{$oTalk->getId()}");
		return $this->oMapper->UpdateTalk($oTalk);
	}
	/**
	 * Получает дополнительные данные(объекты) для разговоров по их ID
	 *
	 * @param array $aTalkId	Список ID сообщений
	 * @param array|null $aAllowData	Список дополнительных типов подгружаемых в объект
	 * @return array
	 */
	public function GetTalksAdditionalData($aTalkId,$aAllowData=null) {
		if (is_null($aAllowData)) {
			$aAllowData=array('user','talk_user','favourite','comment_last');
		}
		func_array_simpleflip($aAllowData);
		if (!is_array($aTalkId)) {
			$aTalkId=array($aTalkId);
		}
		/**
		 * Получаем "голые" разговоры
		 */
		$aTalks=$this->GetTalksByArrayId($aTalkId);
		/**
		 * Формируем ID дополнительных данных, которые нужно получить
		 */
		if (isset($aAllowData['favourite']) and $this->oUserCurrent) {
			$aFavouriteTalks=LS::Make(ModuleFavourite::class)->GetFavouritesByArray($aTalkId,'talk',$this->oUserCurrent->getId());
		}

		$aUserId=array();
		$aCommentLastId=array();
		foreach ($aTalks as $oTalk) {
			if (isset($aAllowData['user'])) {
				$aUserId[]=$oTalk->getUserId();
			}
			if (isset($aAllowData['comment_last']) and $oTalk->getCommentIdLast()) {
				$aCommentLastId[]=$oTalk->getCommentIdLast();
			}
		}
		/**
		 * Получаем дополнительные данные
		 */

		$aTalkUsers=array();
		$aCommentLast=array();
		$aUsers=isset($aAllowData['user']) && is_array($aAllowData['user']) ? LS::Make(ModuleUser::class)->GetUsersAdditionalData($aUserId,$aAllowData['user']) : LS::Make(ModuleUser::class)->GetUsersAdditionalData($aUserId);

		if (isset($aAllowData['talk_user']) and $this->oUserCurrent) {
			$aTalkUsers=$this->GetTalkUsersByArray($aTalkId,$this->oUserCurrent->getId());
		}
		if (isset($aAllowData['comment_last'])) {
			$aCommentLast=LS::Make(ModuleComment::class)->GetCommentsAdditionalData($aCommentLastId,array());
		}

		/**
		 * Добавляем данные к результату - списку разговоров
		 */
		foreach ($aTalks as $oTalk) {
			if (isset($aUsers[$oTalk->getUserId()])) {
				$oTalk->setUser($aUsers[$oTalk->getUserId()]);
			} else {
				$oTalk->setUser(null); // или $oTalk->setUser(new ModuleUser_EntityUser());
			}

			if (isset($aTalkUsers[$oTalk->getId()])) {
				$oTalk->setTalkUser($aTalkUsers[$oTalk->getId()]);
			} else {
				$oTalk->setTalkUser(null);
			}

			if (isset($aFavouriteTalks[$oTalk->getId()])) {
				$oTalk->setIsFavourite(true);
			} else {
				$oTalk->setIsFavourite(false);
			}

			if ($oTalk->getCommentIdLast() and isset($aCommentLast[$oTalk->getCommentIdLast()])) {
				$oTalk->setCommentLast($aCommentLast[$oTalk->getCommentIdLast()]);
			} else {
				$oTalk->setCommentLast(null);
			}
		}
		return $aTalks;
	}
	/**
	 * Получить список разговоров по списку айдишников
	 *
	 * @param array $aTalkId	Список ID сообщений
	 * @return array
	 */
	public function GetTalksByArrayId($aTalkId) {
		if (Config::Get('sys.cache.solid')) {
			return $this->GetTalksByArrayIdSolid($aTalkId);
		}
		if (!is_array($aTalkId)) {
			$aTalkId=array($aTalkId);
		}
		$aTalkId=array_unique($aTalkId);
		$aTalks=array();
		$aTalkIdNotNeedQuery=array();
		/**
		 * Делаем мульти-запрос к кешу
		 */
		$aCacheKeys=func_build_cache_keys($aTalkId,'talk_');
        /** @var ModuleCache $cache */
        $cache = LS::Make(ModuleCache::class);
		if (false !== ($data = $cache->Get($aCacheKeys))) {
			/**
			 * проверяем что досталось из кеша
			 */
			foreach ($aCacheKeys as $sValue => $sKey ) {
				if (array_key_exists($sKey,$data)) {
					if ($data[$sKey]) {
						$aTalks[$data[$sKey]->getId()]=$data[$sKey];
					} else {
						$aTalkIdNotNeedQuery[]=$sValue;
					}
				}
			}
		}
		/**
		 * Смотрим каких разговоров не было в кеше и делаем запрос в БД
		 */
		$aTalkIdNeedQuery=array_diff($aTalkId,array_keys($aTalks));
		$aTalkIdNeedQuery=array_diff($aTalkIdNeedQuery,$aTalkIdNotNeedQuery);
		$aTalkIdNeedStore=$aTalkIdNeedQuery;
		if ($data = $this->oMapper->GetTalksByArrayId($aTalkIdNeedQuery)) {
			foreach ($data as $oTalk) {
				/**
				 * Добавляем к результату и сохраняем в кеш
				 */
				$aTalks[$oTalk->getId()]=$oTalk;
				$cache->Set($oTalk, "talk_{$oTalk->getId()}", array(), 60*60*24*4);
				$aTalkIdNeedStore=array_diff($aTalkIdNeedStore,array($oTalk->getId()));
			}
		}
		/**
		 * Сохраняем в кеш запросы не вернувшие результата
		 */
		foreach ($aTalkIdNeedStore as $sId) {
			$cache->Set(null, "talk_{$sId}", array(), 60*60*24*4);
		}
		/**
		 * Сортируем результат согласно входящему массиву
		 */
		$aTalks=func_array_sort_by_keys($aTalks,$aTalkId);
		return $aTalks;
	}
	/**
	 * Получить список разговоров по списку айдишников, используя общий кеш
	 *
	 * @param array $aTalkId	Список ID сообщений
	 * @return array
	 */
	public function GetTalksByArrayIdSolid($aTalkId) {
		if (!is_array($aTalkId)) {
			$aTalkId=array($aTalkId);
		}
		$aTalkId=array_unique($aTalkId);
		$aTalks=array();
		$s=join(',',$aTalkId);
        /** @var ModuleCache $cache */
        $cache = LS::Make(ModuleCache::class);
		if (false === ($data = $cache->Get("talk_id_{$s}"))) {
			$data = $this->oMapper->GetTalksByArrayId($aTalkId);
			foreach ($data as $oTalk) {
				$aTalks[$oTalk->getId()]=$oTalk;
			}
			$cache->Set($aTalks, "talk_id_{$s}", array("update_talk_user","talk_new"), 60*60*24*1);
			return $aTalks;
		}
		return $data;
	}
	/**
	 * Получить список отношений разговор-юзер по списку айдишников
	 *
	 * @param array $aTalkId	Список ID сообщений
	 * @param int $sUserId	ID пользователя
	 * @return array
	 */
	public function GetTalkUsersByArray($aTalkId,$sUserId) {
		if (!is_array($aTalkId)) {
			$aTalkId=array($aTalkId);
		}
		$aTalkId=array_unique($aTalkId);
		$aTalkUsers=array();
		$aTalkIdNotNeedQuery=array();
		/**
		 * Делаем мульти-запрос к кешу
		 */
        /** @var ModuleCache $cache */
        $cache = LS::Make(ModuleCache::class);
		$aCacheKeys=func_build_cache_keys($aTalkId,'talk_user_','_'.$sUserId);
		if (false !== ($data = $cache->Get($aCacheKeys))) {
			/**
			 * проверяем что досталось из кеша
			 */
			foreach ($aCacheKeys as $sValue => $sKey ) {
				if (array_key_exists($sKey,$data)) {
					if ($data[$sKey]) {
						$aTalkUsers[$data[$sKey]->getTalkId()]=$data[$sKey];
					} else {
						$aTalkIdNotNeedQuery[]=$sValue;
					}
				}
			}
		}
		/**
		 * Смотрим чего не было в кеше и делаем запрос в БД
		 */
		$aTalkIdNeedQuery=array_diff($aTalkId,array_keys($aTalkUsers));
		$aTalkIdNeedQuery=array_diff($aTalkIdNeedQuery,$aTalkIdNotNeedQuery);
		$aTalkIdNeedStore=$aTalkIdNeedQuery;
		if ($data = $this->oMapper->GetTalkUserByArray($aTalkIdNeedQuery,$sUserId)) {
			foreach ($data as $oTalkUser) {
				/**
				 * Добавляем к результату и сохраняем в кеш
				 */
				$aTalkUsers[$oTalkUser->getTalkId()]=$oTalkUser;
				$cache->Set($oTalkUser, "talk_user_{$oTalkUser->getTalkId()}_{$oTalkUser->getUserId()}", array("update_talk_user_{$oTalkUser->getTalkId()}"), 60*60*24*4);
				$aTalkIdNeedStore=array_diff($aTalkIdNeedStore,array($oTalkUser->getTalkId()));
			}
		}
		/**
		 * Сохраняем в кеш запросы не вернувшие результата
		 */
		foreach ($aTalkIdNeedStore as $sId) {
			$cache->Set(null, "talk_user_{$sId}_{$sUserId}", array("update_talk_user_{$sId}"), 60*60*24*4);
		}
		/**
		 * Сортируем результат согласно входящему массиву
		 */
		$aTalkUsers=func_array_sort_by_keys($aTalkUsers,$aTalkId);
		return $aTalkUsers;
	}
	/**
	 * Получает тему разговора по айдишнику
	 *
	 * @param int $sId	ID сообщения
	 * @return ModuleTalk_EntityTalk|null
	 */
	public function GetTalkById($sId) {
		if (!is_numeric($sId)) {
			return null;
		}
		$aTalks=$this->GetTalksAdditionalData($sId);
		if (isset($aTalks[$sId])) {
			$aResult=$this->GetTalkUsersByTalkId($sId);
			foreach ((array)$aResult as $oTalkUser) {
				$aTalkUsers[$oTalkUser->getUserId()]=$oTalkUser;
			}
			$aTalks[$sId]->setTalkUsers($aTalkUsers);
			return $aTalks[$sId];
		}
		return null;
	}
	/**
	 * Добавляет юзера к разговору(теме)
	 *
	 * @param ModuleTalk_EntityTalkUser $oTalkUser	Объект связи пользователя и сообщения(разговора)
	 * @return bool
	 */
	public function AddTalkUser(ModuleTalk_EntityTalkUser $oTalkUser) {
        /** @var ModuleCache $cache */
        $cache = LS::Make(ModuleCache::class);
		$cache->Delete("talk_{$oTalkUser->getTalkId()}");
		$cache->Clean(
			Zend_Cache::CLEANING_MODE_MATCHING_TAG,
			array(
				"update_talk_user_{$oTalkUser->getTalkId()}"
			)
		);
		return $this->oMapper->AddTalkUser($oTalkUser);
	}
	/**
	 * Помечает разговоры как прочитанные
	 *
	 * @param array $aTalkId	Список ID сообщений
	 * @param int $iUserId	ID пользователя
	 */
	public function MarkReadTalkUserByArray($aTalkId,$iUserId) {
		if(!is_array($aTalkId)){
			$aTalkId=array($aTalkId);
		}
        /** @var ModuleTalk $talk */
        $talk = LS::Make(ModuleTalk::class);
		foreach ($aTalkId as $sTalkId) {
			if ($oTalk=$talk->GetTalkById((string)$sTalkId)) {
				if ($oTalkUser=$talk->GetTalkUser($oTalk->getId(),$iUserId)) {
					$oTalkUser->setDateLast(date("Y-m-d H:i:s"));
					if ($oTalk->getCommentIdLast()) {
						$oTalkUser->setCommentIdLast($oTalk->getCommentIdLast());
					}
					$oTalkUser->setCommentCountNew(0);
					$talk->UpdateTalkUser($oTalkUser);
				}
			}
		}
	}
	/**
	 * Удаляет юзера из разговора
	 *
	 * @param array $aTalkId	Список ID сообщений
	 * @param int $sUserId	ID пользователя
	 * @param int $iActive	Статус связи
	 * @return bool
	 */
	public function DeleteTalkUserByArray($aTalkId,$sUserId,$iActive=self::TALK_USER_DELETE_BY_SELF) {
		if(!is_array($aTalkId)){
			$aTalkId=array($aTalkId);
		}
		// Удаляем для каждого отметку избранного
		foreach ($aTalkId as $sTalkId) {
			$this->DeleteFavouriteTalk(
				new ModuleFavourite_EntityFavourite(array(
									  'target_id' => (string)$sTalkId,
									  'target_type' => 'talk',
									  'user_id' => $sUserId
								  )
				)
			);
		}
		// Нужно почистить зависимые кеши
        /** @var ModuleCache $cache */
        $cache = LS::Make(ModuleCache::class);
		foreach ($aTalkId as $sTalkId) {
			$sTalkId=(string)$sTalkId;
			$cache->Clean(
				Zend_Cache::CLEANING_MODE_MATCHING_TAG,
				array("update_talk_user_{$sTalkId}")
			);
		}
		$cache->Clean(Zend_Cache::CLEANING_MODE_MATCHING_TAG,array("update_talk_user"));
		$ret =  $this->oMapper->DeleteTalkUserByArray($aTalkId,$sUserId,$iActive);

		// Удаляем пустые беседы, если в них нет пользователей
		foreach ($aTalkId as $sTalkId) {
			$sTalkId=(string)$sTalkId;
			if (!count($this->GetUsersTalk($sTalkId, array(self::TALK_USER_ACTIVE)))) {
				$this->RemoveTalk($sTalkId);
			}
		}
		return $ret;
	}
	/**
	 * Есть ли юзер в этом разговоре
	 *
	 * @param int $sTalkId	ID разговора
	 * @param int $sUserId	ID пользователя
	 * @return ModuleTalk_EntityTalkUser|null
	 */
	public function GetTalkUser($sTalkId,$sUserId) {
		$aTalkUser=$this->GetTalkUsersByArray($sTalkId,$sUserId);
		if (isset($aTalkUser[$sTalkId])) {
			return $aTalkUser[$sTalkId];
		}
		return null;
	}
	/**
	 * Получить все темы разговора где есть юзер
	 *
	 * @param  int $sUserId	ID пользователя
	 * @param  int    $iPage	Номер страницы
	 * @param  int    $iPerPage	Количество элементов на страницу
	 * @return array('collection'=>array,'count'=>int)
	 */
	public function GetTalksByUserId($sUserId,$iPage,$iPerPage) {
		$data=array(
			'collection'=>$this->oMapper->GetTalksByUserId($sUserId,$iCount,$iPage,$iPerPage),
			'count'=>$iCount
		);
		$aTalks=$this->GetTalksAdditionalData($data['collection']);
		/**
		 * Добавляем данные об участниках разговора
		 */
		foreach ($aTalks as $oTalk) {
			$aResult=$this->GetTalkUsersByTalkId($oTalk->getId());
			foreach ((array)$aResult as $oTalkUser) {
				$aTalkUsers[$oTalkUser->getUserId()]=$oTalkUser;
			}
			$oTalk->setTalkUsers($aTalkUsers);
		}
		$data['collection']=$aTalks;
		return $data;
	}
	/**
	 * Получить все темы разговора по фильтру
	 *
	 * @param  array  $aFilter	Фильтр
	 * @param  int    $iPage	Номер страницы
	 * @param  int    $iPerPage	Количество элементов на страницу
	 * @return array('collection'=>array,'count'=>int)
	 */
	public function GetTalksByFilter($aFilter,$iPage,$iPerPage) {
		$data=array(
			'collection'=>$this->oMapper->GetTalksByFilter($aFilter,$iCount,$iPage,$iPerPage),
			'count'=>$iCount
		);
		$aTalks=$this->GetTalksAdditionalData($data['collection']);
		/**
		 * Добавляем данные об участниках разговора
		 */
		foreach ($aTalks as $oTalk) {
			$aResult=$this->GetTalkUsersByTalkId($oTalk->getId());
			$aTalkUsers=array();
			foreach ((array)$aResult as $oTalkUser) {
				$aTalkUsers[$oTalkUser->getUserId()]=$oTalkUser;
			}
			$oTalk->setTalkUsers($aTalkUsers);
		}
		$data['collection']=$aTalks;
		return $data;
	}
	/**
	 * Обновляет связку разговор-юзер
	 *
	 * @param ModuleTalk_EntityTalkUser $oTalkUser	Объект связи пользователя с разговором
	 * @return bool
	 */
	public function UpdateTalkUser(ModuleTalk_EntityTalkUser $oTalkUser) {
		//чистим зависимые кеши
        /** @var ModuleCache $cache */
        $cache = LS::Make(ModuleCache::class);
		$cache->Clean(Zend_Cache::CLEANING_MODE_MATCHING_TAG,array("talk_read_user_{$oTalkUser->getUserId()}"));
		$cache->Delete("talk_user_{$oTalkUser->getTalkId()}_{$oTalkUser->getUserId()}");
		return $this->oMapper->UpdateTalkUser($oTalkUser);
	}
	/**
	 * Получает число новых тем и комментов где есть юзер
	 *
	 * @param int $sUserId	ID пользователя
	 * @return int
	 */
	public function GetCountTalkNew($sUserId) {
        /** @var ModuleCache $cache */
        $cache = LS::Make(ModuleCache::class);
		if (false === ($data = $cache->Get("talk_count_all_new_user_{$sUserId}"))) {
			$data = $this->oMapper->GetCountCommentNew($sUserId)+$this->oMapper->GetCountTalkNew($sUserId);
			$cache->Set($data, "talk_count_all_new_user_{$sUserId}", array("talk_new","update_talk_user","talk_read_user_{$sUserId}"), 60*60*24);
		}
		return $data;
	}
	/**
	 * Получает список юзеров в теме разговора
	 *
	 * @param  int $sTalkId	ID разговора
	 * @param  array  $aActive	Список статусов
	 * @return array
	 */
	public function GetUsersTalk($sTalkId,$aActive=array()) {
		if(!is_array($aActive)) $aActive = array($aActive);

		$data=$this->oMapper->GetUsersTalk($sTalkId,$aActive);
		return LS::Make(ModuleUser::class)->GetUsersAdditionalData($data);
	}
	/**
	 * Возвращает массив пользователей, участвующих в разговоре
	 *
	 * @param  int $sTalkId	ID разговора
	 * @return array
	 */
	public function GetTalkUsersByTalkId($sTalkId,$aAllowData=null) {
		if (is_null($aAllowData)) {
			$aAllowData=array('user'=>array());
		}
        /** @var ModuleCache $cache */
        $cache = LS::Make(ModuleCache::class);
		if (false === ($aTalkUsers = $cache->Get("talk_relation_user_by_talk_id_{$sTalkId}"))) {
			$aTalkUsers = $this->oMapper->GetTalkUsers($sTalkId);
			$cache->Set($aTalkUsers, "talk_relation_user_by_talk_id_{$sTalkId}", array("update_talk_user_{$sTalkId}"), 60*60*24*1);
		}

		if($aTalkUsers) {
			$aUserId=array();
			foreach ($aTalkUsers as $oTalkUser) {
				$aUserId[]=$oTalkUser->getUserId();
			}
			$aUsers = LS::Make(ModuleUser::class)->GetUsersAdditionalData($aUserId,isset($aAllowData['user']) && is_array($aAllowData['user']) ? $aAllowData['user'] : null);

			foreach ($aTalkUsers as $oTalkUser){
				if(isset($aUsers[$oTalkUser->getUserId()])) {
					$oTalkUser->setUser($aUsers[$oTalkUser->getUserId()]);
				} else {
					$oTalkUser->setUser(null);
				}
			}
		}
		return $aTalkUsers;
	}
	/**
	 * Увеличивает число новых комментов у юзеров
	 *
	 * @param int $sTalkId	ID разговора
	 * @param array $aExcludeId	Список ID пользователей для исключения
	 * @return int
	 */
	public function increaseCountCommentNew($sTalkId,$aExcludeId=null) {
        /** @var ModuleCache $cache */
        $cache = LS::Make(ModuleCache::class);
		$cache->Clean(Zend_Cache::CLEANING_MODE_MATCHING_TAG,array("update_talk_user_{$sTalkId}"));
		$cache->Clean(Zend_Cache::CLEANING_MODE_MATCHING_TAG,array("update_talk_user"));
		return $this->oMapper->increaseCountCommentNew($sTalkId,$aExcludeId);
	}
	/**
	 * Получает привязку письма к ибранному(добавлено ли письмо в избранное у юзера)
	 *
	 * @param  int $sTalkId	ID разговора
	 * @param  int $sUserId	ID пользователя
	 * @return ModuleFavourite_EntityFavourite|null
	 */
	public function GetFavouriteTalk($sTalkId,$sUserId) {
		return LS::Make(ModuleFavourite::class)->GetFavourite($sTalkId,'talk',$sUserId);
	}
	/**
	 * Получить список избранного по списку айдишников
	 *
	 * @param array $aTalkId	Список ID разговоров
	 * @param int $sUserId	ID пользователя
	 * @return array
	 */
	public function GetFavouriteTalkByArray($aTalkId,$sUserId) {
		return LS::Make(ModuleFavourite::class)->GetFavouritesByArray($aTalkId,'talk',$sUserId);
	}
	/**
	 * Получить список избранного по списку айдишников, но используя единый кеш
	 *
	 * @param array  $aTalkId	Список ID разговоров
	 * @param int    $sUserId	ID пользователя
	 * @return array
	 */
	public function GetFavouriteTalksByArraySolid($aTalkId,$sUserId) {
		return LS::Make(ModuleFavourite::class)->GetFavouritesByArraySolid($aTalkId,'talk',$sUserId);
	}
	/**
	 * Получает список писем из избранного пользователя
	 *
	 * @param  int $sUserId	ID пользователя
	 * @param  int    $iCurrPage	Номер текущей страницы
	 * @param  int    $iPerPage	Количество элементов на страницу
	 * @return array('collection'=>array,'count'=>int)
	 */
	public function GetTalksFavouriteByUserId($sUserId,$iCurrPage,$iPerPage) {
		// Получаем список идентификаторов избранных комментов
		$data = LS::Make(ModuleFavourite::class)->GetFavouritesByUserId($sUserId,'talk',$iCurrPage,$iPerPage);
		// Получаем комменты по переданому массиву айдишников
		$aTalks=$this->GetTalksAdditionalData($data['collection']);

		/**
		 * Добавляем данные об участниках разговора
		 */
		foreach ($aTalks as $oTalk) {
			$aResult=$this->GetTalkUsersByTalkId($oTalk->getId());
			$aTalkUsers=array();
			foreach ((array)$aResult as $oTalkUser) {
				$aTalkUsers[$oTalkUser->getUserId()]=$oTalkUser;
			}
			$oTalk->setTalkUsers($aTalkUsers);
		}
		$data['collection']=$aTalks;
		return $data;
	}
	/**
	 * Возвращает число писем в избранном
	 *
	 * @param  int $sUserId ID пользователя
	 * @return int
	 */
	public function GetCountTalksFavouriteByUserId($sUserId) {
		return LS::Make(ModuleFavourite::class)->GetCountFavouritesByUserId($sUserId,'talk');
	}
	/**
	 * Добавляет письмо в избранное
	 *
	 * @param  ModuleFavourite_EntityFavourite $oFavourite	Объект избранного
	 * @return bool
	 */
	public function AddFavouriteTalk(ModuleFavourite_EntityFavourite $oFavourite) {
		return ($oFavourite->getTargetType()=='talk')
			? LS::Make(ModuleFavourite::class)->AddFavourite($oFavourite)
			: false;
	}
	/**
	 * Удаляет письмо из избранного
	 *
	 * @param  ModuleFavourite_EntityFavourite $oFavourite	Объект избранного
	 * @return bool
	 */
	public function DeleteFavouriteTalk(ModuleFavourite_EntityFavourite $oFavourite) {
		return ($oFavourite->getTargetType()=='talk')
			? LS::Make(ModuleFavourite::class)->DeleteFavourite($oFavourite)
			: false;
	}
	/**
	 * Получает информацию о пользователях, занесенных в блеклист
	 *
	 * @param  int $sUserId	ID пользователя
	 * @return array
	 */
	public function GetBlacklistByUserId($sUserId) {
		$data=$this->oMapper->GetBlacklistByUserId($sUserId);
		return LS::Make(ModuleUser::class)->GetUsersAdditionalData($data);
	}
	/**
	 * Возвращает пользователей, у которых данный занесен в Blacklist
	 *
	 * @param  int $sUserId ID пользователя
	 * @return array
	 */
	public function GetBlacklistByTargetId($sUserId) {
		return $this->oMapper->GetBlacklistByTargetId($sUserId);
	}
	/**
	 * Добавление пользователя в блеклист по переданному идентификатору
	 *
	 * @param  int $sTargetId	ID пользователя, которого добавляем в блэклист
	 * @param  int $sUserId	ID пользователя
	 * @return bool
	 */
	public function AddUserToBlacklist($sTargetId, $sUserId) {
		return $this->oMapper->AddUserToBlacklist($sTargetId, $sUserId);
	}
	/**
	 * Добавление пользователя в блеклист по списку идентификаторов
	 *
	 * @param  array $aTargetId	Список ID пользователей, которых добавляем в блэклист
	 * @param  int $sUserId	ID пользователя
	 * @return bool
	 */
	public function AddUserArrayToBlacklist($aTargetId, $sUserId) {
		foreach ((array)$aTargetId as $oUser) {
			$aUsersId[]=$oUser instanceof ModuleUser_EntityUser ? $oUser->getId() : (int)$oUser;
		}
		return $this->oMapper->AddUserArrayToBlacklist($aUsersId, $sUserId);
	}
	/**
	 * Удаляем пользователя из блеклиста
	 *
	 * @param  int $sTargetId	ID пользователя, которого удаляем из блэклиста
	 * @param  int $sUserId	ID пользователя
	 * @return bool
	 */
	public function DeleteUserFromBlacklist($sTargetId, $sUserId) {
		return $this->oMapper->DeleteUserFromBlacklist($sTargetId, $sUserId);
	}
	/**
	 * Возвращает список последних инбоксов пользователя,
	 * отправленных не более чем $iTimeLimit секунд назад
	 *
	 * @param  int $sUserId	ID пользователя
	 * @param  int    $iTimeLimit	Количество секунд
	 * @param  int    $iCountLimit	Количество
	 * @return array
	 */
	public function GetLastTalksByUserId($sUserId,$iTimeLimit,$iCountLimit=1) {
		$aFilter = array(
			'sender_id' => $sUserId,
			'date_min' => date("Y-m-d H:i:s",time()-$iTimeLimit),
		);
		$aTalks = $this->GetTalksByFilter($aFilter,1,$iCountLimit);

		return $aTalks;
	}
	/**
	 * Удаление письма из БД
	 *
	 * @param int $iTalkId	ID разговора
	 */
	public function DeleteTalk($iTalkId) {
		$this->oMapper->deleteTalk($iTalkId);
		/**
		 * Удаляем комментарии к письму.
		 * При удалении комментариев они удаляются из избранного,прямого эфира и голоса за них
		 */
		LS::Make(ModuleComment::class)->DeleteCommentByTargetId($iTalkId,'talk');
	}
	/**
	 * Удаление письма в корзину
	 *
	 * @param int $iTalkId	ID разговора
	 */
	public function RemoveTalk($iTalkId) {
		if($oTalk = LS::Make(ModuleTalk::class)->GetTalkById($iTalkId)) {
			$oTalk->setDeleted(true);
			$this->oMapper->UpdateTalk($oTalk);
		}
	}
}