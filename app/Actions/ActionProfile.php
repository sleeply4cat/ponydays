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

namespace App\Actions;

use App\Modules\ACL\ModuleACL;
use App\Modules\Blog\ModuleBlog;
use App\Modules\Comment\ModuleComment;
use App\Modules\Favourite\ModuleFavourite;
use App\Modules\Notify\ModuleNotify;
use App\Modules\Stream\ModuleStream;
use App\Modules\Talk\ModuleTalk;
use App\Modules\Topic\ModuleTopic;
use App\Modules\User\Entity\ModuleUser_EntityFriend;
use App\Modules\User\Entity\ModuleUser_EntityNote;
use App\Modules\User\Entity\ModuleUser_EntityUser;
use App\Modules\User\ModuleUser;
use App\Modules\Wall\Entity\ModuleWall_EntityWall;
use App\Modules\Wall\ModuleWall;
use Engine\Engine;
use Engine\Action;
use Engine\Config;
use Engine\LS;
use Engine\Modules\Hook\ModuleHook;
use Engine\Modules\Lang\ModuleLang;
use Engine\Modules\Message\ModuleMessage;
use Engine\Modules\Text\ModuleText;
use Engine\Modules\Viewer\ModuleViewer;
use Engine\Router;

/**
 * Экшен обработки профайла юзера, т.е. УРЛ вида /profile/login/
 *
 * @package actions
 * @since 1.0
 */
class ActionProfile extends Action {
	/**
	 * Объект юзера чей профиль мы смотрим
	 *
	 * @var ModuleUser_EntityUser|null
	 */
	protected $oUserProfile;
	/**
	 * Главное меню
	 *
	 * @var string
	 */
	protected $sMenuHeadItemSelect='people';
	/**
	 * Субменю
	 *
	 * @var string
	 */
	protected $sMenuSubItemSelect='';
	/**
	 * Текущий пользователь
	 *
	 * @var ModuleUser_EntityUser|null
	 */
	protected $oUserCurrent;

	/**
	 * Инициализация
	 */
	public function Init() {
		$this->oUserCurrent=LS::Make(ModuleUser::class)->GetUserCurrent();
	}
	/**
	 * Регистрация евентов
	 */
	protected function RegisterEvent() {
		$this->AddEvent('friendoffer','EventFriendOffer');
		$this->AddEvent('ajaxfriendadd', 'EventAjaxFriendAdd');
		$this->AddEvent('ajaxfrienddelete', 'EventAjaxFriendDelete');
		$this->AddEvent('ajaxfriendaccept', 'EventAjaxFriendAccept');
		$this->AddEvent('ajax-note-save', 'EventAjaxNoteSave');
		$this->AddEvent('ajax-note-remove', 'EventAjaxNoteRemove');

		$this->AddEventPreg('/^.+$/i','/^(whois)?$/i','EventWhois');

		$this->AddEventPreg('/^.+$/i','/^wall$/i','/^$/i','EventWall');
		$this->AddEventPreg('/^.+$/i','/^wall$/i','/^add$/i','EventWallAdd');
		$this->AddEventPreg('/^.+$/i','/^wall$/i','/^load$/i','EventWallLoad');
		$this->AddEventPreg('/^.+$/i','/^wall$/i','/^load-reply$/i','EventWallLoadReply');
		$this->AddEventPreg('/^.+$/i','/^wall$/i','/^remove$/i','EventWallRemove');

		$this->AddEventPreg('/^.+$/i','/^favourites$/i','/^comments$/i','/^(page([1-9]\d{0,5}))?$/i','EventFavouriteComments');
		$this->AddEventPreg('/^.+$/i','/^favourites$/i','/^(page([1-9]\d{0,5}))?$/i','EventFavourite');
		$this->AddEventPreg('/^.+$/i','/^favourites$/i','/^topics/i','/^(page([1-9]\d{0,5}))?$/i','EventFavourite');
		$this->AddEventPreg('/^.+$/i','/^favourites$/i','/^topics/i','/^tag/i','/^.+/i','/^(page([1-9]\d{0,5}))?$/i','EventFavouriteTopicsTag');

		$this->AddEventPreg('/^.+$/i','/^created/i','/^notes/i','/^(page([1-9]\d{0,5}))?$/i','EventCreatedNotes');
		$this->AddEventPreg('/^.+$/i','/^created/i','/^(page([1-9]\d{0,5}))?$/i','EventCreatedTopics');
		$this->AddEventPreg('/^.+$/i','/^created/i','/^topics/i','/^(page([1-9]\d{0,5}))?$/i','EventCreatedTopics');
		$this->AddEventPreg('/^.+$/i','/^created/i','/^comments$/i','/^(page([1-9]\d{0,5}))?$/i','EventCreatedComments');

		$this->AddEventPreg('/^.+$/i','/^friends/i','/^(page([1-9]\d{0,5}))?$/i','EventFriends');
		$this->AddEventPreg('/^.+$/i','/^stream/i','/^$/i','EventStream');

		$this->AddEventPreg('/^changemail$/i','/^confirm-from/i','/^\w{32}$/i','EventChangemailConfirmFrom');
		$this->AddEventPreg('/^changemail$/i','/^confirm-to/i','/^\w{32}$/i','EventChangemailConfirmTo');
	}

	/**********************************************************************************
	 ************************ РЕАЛИЗАЦИЯ ЭКШЕНА ***************************************
	 **********************************************************************************
	 */

	/**
	 * Проверка корректности профиля
	 */
	protected function CheckUserProfile() {
		/**
		 * Проверяем есть ли такой юзер
		 */
		if (!($this->oUserProfile=LS::Make(ModuleUser::class)->GetUserByLogin($this->sCurrentEvent))) {
			return false;
		}
		return true;
	}
	/**
	 * Чтение активности пользователя (stream)
	 */
	protected function EventStream() {
		if (!$this->CheckUserProfile()) {
			return parent::EventNotFound();
		}
		/**
		 * Читаем события
		 */
		$aEvents = LS::Make(ModuleStream::class)->ReadByUserId($this->oUserProfile->getId());
		LS::Make(ModuleViewer::class)->Assign('bDisableGetMoreButton', LS::Make(ModuleStream::class)->GetCountByUserId($this->oUserProfile->getId()) < Config::Get('module.stream.count_default'));
		LS::Make(ModuleViewer::class)->Assign('aStreamEvents', $aEvents);
		if (count($aEvents)) {
			$oEvenLast=end($aEvents);
			LS::Make(ModuleViewer::class)->Assign('iStreamLastId', $oEvenLast->getId());
		}
		$this->SetTemplateAction('stream');
	}
	/**
	 * Список друзей пользователей
	 */
	protected function EventFriends() {
		if (!$this->CheckUserProfile()) {
			return parent::EventNotFound();
		}
		/**
		 * Передан ли номер страницы
		 */
		$iPage=$this->GetParamEventMatch(1,2) ? $this->GetParamEventMatch(1,2) : 1;
		/**
		 * Получаем список комментов
		 */
		$aResult=LS::Make(ModuleUser::class)->GetUsersFriend($this->oUserProfile->getId(),$iPage,Config::Get('module.user.per_page'));
		$aFriends=$aResult['collection'];
		/**
		 * Формируем постраничность
		 */
		$aPaging=LS::Make(ModuleViewer::class)->MakePaging($aResult['count'],$iPage,Config::Get('module.user.per_page'),Config::Get('pagination.pages.count'),$this->oUserProfile->getUserWebPath().'friends');
		/**
		 * Загружаем переменные в шаблон
		 */
		LS::Make(ModuleViewer::class)->Assign('aPaging',$aPaging);
		LS::Make(ModuleViewer::class)->Assign('aFriends',$aFriends);

		LS::Make(ModuleViewer::class)->AddHtmlTitle(LS::Make(ModuleLang::class)->Get('user_menu_profile_friends').' '.$this->oUserProfile->getLogin());

		$this->SetTemplateAction('friends');
	}
	/**
	 * Список топиков пользователя
	 */
	protected function EventCreatedTopics() {
		if (!$this->CheckUserProfile()) {
			return parent::EventNotFound();
		}
		$this->sMenuSubItemSelect='topics';
		/**
		 * Передан ли номер страницы
		 */
		if ($this->GetParamEventMatch(1,0)=='topics') {
			$iPage=$this->GetParamEventMatch(2,2) ? $this->GetParamEventMatch(2,2) : 1;
		} else {
			$iPage=$this->GetParamEventMatch(1,2) ? $this->GetParamEventMatch(1,2) : 1;
		}
		/**
		 * Получаем список топиков
		 */
		$aResult=LS::Make(ModuleTopic::class)->GetTopicsPersonalByUser($this->oUserProfile->getId(),1,$iPage,Config::Get('module.topic.per_page'));
		$aTopics=$aResult['collection'];
		/**
		 * Вызов хуков
		 */
		LS::Make(ModuleHook::class)->Run('topics_list_show',array('aTopics'=>$aTopics));

        /** @var ModuleViewer $viewer */
        $viewer = LS::Make(ModuleViewer::class);
        /**
		 * Формируем постраничность
		 */
		$aPaging=$viewer->MakePaging($aResult['count'],$iPage,Config::Get('module.topic.per_page'),Config::Get('pagination.pages.count'),$this->oUserProfile->getUserWebPath().'created/topics');
		/**
		 * Загружаем переменные в шаблон
		 */
        $viewer->Assign('aPaging',$aPaging);
        $viewer->Assign('aTopics',$aTopics);
        $viewer->AddHtmlTitle(LS::Make(ModuleLang::class)->Get('user_menu_publication').' '.$this->oUserProfile->getLogin());
        $viewer->AddHtmlTitle(LS::Make(ModuleLang::class)->Get('user_menu_publication_blog'));
        $viewer->SetHtmlRssAlternate(Router::GetPath('rss').'personal_blog/'.$this->oUserProfile->getLogin().'/',$this->oUserProfile->getLogin());
		/**
		 * Устанавливаем шаблон вывода
		 */

		$this->SetTemplateAction('created_topics');
	}
	/**
	 * Вывод комментариев пользователя
	 */
	protected function EventCreatedComments() {
		if (!$this->CheckUserProfile()) {
			return parent::EventNotFound();
		}
		$this->sMenuSubItemSelect='comments';
		/**
		 * Передан ли номер страницы
		 */
		$iPage=$this->GetParamEventMatch(2,2) ? $this->GetParamEventMatch(2,2) : 1;
		/**
		 * Получаем список комментов
		 */
		$aResult=LS::Make(ModuleComment::class)->GetCommentsByUserId($this->oUserProfile->getId(),'topic',$iPage,Config::Get('module.comment.per_page'));
		$aComments=$aResult['collection'];
		/**
		 * Формируем постраничность
		 */
		$aPaging=LS::Make(ModuleViewer::class)->MakePaging($aResult['count'],$iPage,Config::Get('module.comment.per_page'),Config::Get('pagination.pages.count'),$this->oUserProfile->getUserWebPath().'created/comments');
		/**
		 * Загружаем переменные в шаблон
		 */
		LS::Make(ModuleViewer::class)->Assign('aPaging',$aPaging);
		LS::Make(ModuleViewer::class)->Assign('aComments',$aComments);
		LS::Make(ModuleViewer::class)->Assign('bEnableCommentsVoteInfo',LS::Make(ModuleACL::class)->CheckSimpleAccessLevel(Config::Get('acl.vote_list.comment.ne_enable_level'), $this->oUserCurrent, null, '__non_checkable_visible__'));

		LS::Make(ModuleViewer::class)->AddHtmlTitle(LS::Make(ModuleLang::class)->Get('user_menu_publication').' '.$this->oUserProfile->getLogin());
		LS::Make(ModuleViewer::class)->AddHtmlTitle(LS::Make(ModuleLang::class)->Get('user_menu_publication_comment'));
		/**
		 * Устанавливаем шаблон вывода
		 */
		$this->SetTemplateAction('created_comments');
	}
	/**
	 * Выводит список избранноего юзера
	 *
	 */
	protected function EventFavourite() {
		if (!$this->CheckUserProfile()) {
			return parent::EventNotFound();
		}
		$this->sMenuSubItemSelect='topics';
		/**
		 * Передан ли номер страницы
		 */
		if ($this->GetParamEventMatch(1,0)=='topics') {
			$iPage=$this->GetParamEventMatch(2,2) ? $this->GetParamEventMatch(2,2) : 1;
		} else {
			$iPage=$this->GetParamEventMatch(1,2) ? $this->GetParamEventMatch(1,2) : 1;
		}
		/**
		 * Получаем список избранных топиков
		 */
		$aResult=LS::Make(ModuleTopic::class)->GetTopicsFavouriteByUserId($this->oUserProfile->getId(),$iPage,Config::Get('module.topic.per_page'));
		$aTopics=$aResult['collection'];
		/**
		 * Вызов хуков
		 */
		LS::Make(ModuleHook::class)->Run('topics_list_show',array('aTopics'=>$aTopics));
		/**
		 * Формируем постраничность
		 */
		$aPaging=LS::Make(ModuleViewer::class)->MakePaging($aResult['count'],$iPage,Config::Get('module.topic.per_page'),Config::Get('pagination.pages.count'),$this->oUserProfile->getUserWebPath().'favourites/topics');
		/**
		 * Загружаем переменные в шаблон
		 */
		LS::Make(ModuleViewer::class)->Assign('aPaging',$aPaging);
		LS::Make(ModuleViewer::class)->Assign('aTopics',$aTopics);

		LS::Make(ModuleViewer::class)->AddHtmlTitle(LS::Make(ModuleLang::class)->Get('user_menu_profile').' '.$this->oUserProfile->getLogin());
		LS::Make(ModuleViewer::class)->AddHtmlTitle(LS::Make(ModuleLang::class)->Get('user_menu_profile_favourites'));
		/**
		 * Устанавливаем шаблон вывода
		 */
		$this->SetTemplateAction('favourite_topics');
	}
	/**
	 * Список топиков из избранного по тегу
	 */
	protected function EventFavouriteTopicsTag() {
		if (!$this->CheckUserProfile()) {
			return parent::EventNotFound();
		}
		/**
		 * Пользователь авторизован и просматривает свой профиль?
		 */
		if (!$this->oUserCurrent or $this->oUserProfile->getId()!=$this->oUserCurrent->getId()) {
			return parent::EventNotFound();
		}
		$this->sMenuSubItemSelect='topics';
		$sTag=$this->GetParamEventMatch(3,0);
		/*
		 * Передан ли номер страницы
		 */
		$iPage=$this->GetParamEventMatch(4,2) ? $this->GetParamEventMatch(4,2) : 1;
		/**
		 * Получаем список избранных топиков
		 */
		$aResult=LS::Make(ModuleFavourite::class)->GetTags(array('target_type'=>'topic','user_id'=>$this->oUserProfile->getId(),'text'=>$sTag),array('target_id'=>'desc'),$iPage,Config::Get('module.topic.per_page'));
		$aTopicId=array();
		foreach($aResult['collection'] as $oTag) {
			$aTopicId[]=$oTag->getTargetId();
		}
		$aTopics=LS::Make(ModuleTopic::class)->GetTopicsAdditionalData($aTopicId);
		/**
		 * Формируем постраничность
		 */
		$aPaging=LS::Make(ModuleViewer::class)->MakePaging($aResult['count'],$iPage,Config::Get('module.topic.per_page'),Config::Get('pagination.pages.count'),$this->oUserProfile->getUserWebPath().'favourites/topics/tag/'.htmlspecialchars($sTag));
		/**
		 * Загружаем переменные в шаблон
		 */
		LS::Make(ModuleViewer::class)->Assign('aPaging',$aPaging);
		LS::Make(ModuleViewer::class)->Assign('aTopics',$aTopics);
		LS::Make(ModuleViewer::class)->Assign('sFavouriteTag',htmlspecialchars($sTag));

		LS::Make(ModuleViewer::class)->AddHtmlTitle(LS::Make(ModuleLang::class)->Get('user_menu_profile').' '.$this->oUserProfile->getLogin());
		LS::Make(ModuleViewer::class)->AddHtmlTitle(LS::Make(ModuleLang::class)->Get('user_menu_profile_favourites'));
		/**
		 * Устанавливаем шаблон вывода
		 */
		$this->SetTemplateAction('favourite_topics');
	}
	/**
	 * Выводит список избранноего юзера
	 *
	 */
	protected function EventFavouriteComments() {
		if (!$this->CheckUserProfile()) {
			return parent::EventNotFound();
		}
		$this->sMenuSubItemSelect='comments';
		/**
		 * Передан ли номер страницы
		 */
		$iPage=$this->GetParamEventMatch(2,2) ? $this->GetParamEventMatch(2,2) : 1;
		/**
		 * Получаем список избранных комментариев
		 */
		$aResult=LS::Make(ModuleComment::class)->GetCommentsFavouriteByUserId($this->oUserProfile->getId(),$iPage,Config::Get('module.comment.per_page'));
		$aComments=$aResult['collection'];
		/**
		 * Формируем постраничность
		 */
		$aPaging=LS::Make(ModuleViewer::class)->MakePaging($aResult['count'],$iPage,Config::Get('module.comment.per_page'),Config::Get('pagination.pages.count'),$this->oUserProfile->getUserWebPath().'favourites/comments');
		/**
		 * Загружаем переменные в шаблон
		 */
		LS::Make(ModuleViewer::class)->Assign('aPaging',$aPaging);
		LS::Make(ModuleViewer::class)->Assign('aComments',$aComments);
		LS::Make(ModuleViewer::class)->Assign('bEnableCommentsVoteInfo',LS::Make(ModuleACL::class)->CheckSimpleAccessLevel(Config::Get('acl.vote_list.comment.ne_enable_level'), $this->oUserCurrent, null, '__non_checkable_visible__'));

		LS::Make(ModuleViewer::class)->AddHtmlTitle(LS::Make(ModuleLang::class)->Get('user_menu_profile').' '.$this->oUserProfile->getLogin());
		LS::Make(ModuleViewer::class)->AddHtmlTitle(LS::Make(ModuleLang::class)->Get('user_menu_profile_favourites_comments'));
		/**
		 * Устанавливаем шаблон вывода
		 */
		$this->SetTemplateAction('favourite_comments');
	}
	/**
	 * Показывает инфу профиля
	 *
	 */
	protected function EventWhois() {
		if (!$this->CheckUserProfile()) {
			return parent::EventNotFound();
		}
		Router::Location("/profile/".$this->oUserProfile->getLogin()."/created/topics");
	}
	/**
	 * Отображение стены пользователя
	 */
	public function EventWall() {
		if (!$this->CheckUserProfile()) {
			return parent::EventNotFound();
		}
		/**
		 * Получаем записи стены
		 */
		$aWall=LS::Make(ModuleWall::class)->GetWall(array('wall_user_id'=>$this->oUserProfile->getId(),'pid'=>null),array('id'=>'desc'),1,Config::Get('module.wall.per_page'));
		LS::Make(ModuleViewer::class)->Assign('aWall',$aWall['collection']);
		LS::Make(ModuleViewer::class)->Assign('iCountWall',$aWall['count']);

		/**
		 * Устанавливаем шаблон вывода
		 */
		$this->SetTemplateAction('wall');
	}
	/**
	 * Добавление записи на стену
	 */
	public function EventWallAdd() {
		/**
		 * Устанавливаем формат Ajax ответа
		 */
		LS::Make(ModuleViewer::class)->SetResponseAjax('json');
		/**
		 * Пользователь авторизован?
		 */
		if (!$this->oUserCurrent) {
			return parent::EventNotFound();
		}
		if (!$this->CheckUserProfile()) {
			return parent::EventNotFound();
		}
		/**
		 * Создаем запись
		 */
		$oWall = new ModuleWall_EntityWall();
		$oWall->_setValidateScenario('add');
		$oWall->setWallUserId($this->oUserProfile->getId());
		$oWall->setUserId($this->oUserCurrent->getId());
		$oWall->setText(getRequestStr('sText'));
		$oWall->setPid(getRequestStr('iPid'));

		LS::Make(ModuleHook::class)->Run('wall_add_validate_before', array('oWall'=>$oWall));
		if ($oWall->_Validate()) {
			/**
			 * Экранируем текст и добавляем запись в БД
			 */
			$oWall->setText(LS::Make(ModuleText::class)->Parser($oWall->getText()));
			LS::Make(ModuleHook::class)->Run('wall_add_before', array('oWall'=>$oWall));
			if (LS::Make(ModuleWall::class)->AddWall($oWall)) {
				LS::Make(ModuleHook::class)->Run('wall_add_after', array('oWall'=>$oWall));
				/**
				 * Отправляем уведомления
				 */
				if ($oWall->getWallUserId()!=$oWall->getUserId()) {
					LS::Make(ModuleNotify::class)->SendWallNew($oWall,$this->oUserCurrent);
				}
				if ($oWallParent=$oWall->GetPidWall() and $oWallParent->getUserId()!=$oWall->getUserId()) {
					LS::Make(ModuleNotify::class)->SendWallReply($oWallParent,$oWall,$this->oUserCurrent);
				}
				/**
				 * Добавляем событие в ленту
				 */
				LS::Make(ModuleStream::class)->Write($oWall->getUserId(), 'add_wall', $oWall->getId());
			} else {
				LS::Make(ModuleMessage::class)->AddError(LS::Make(ModuleLang::class)->Get('wall_add_error'),LS::Make(ModuleLang::class)->Get('error'));
			}
		} else {
			LS::Make(ModuleMessage::class)->AddError($oWall->_getValidateError(),LS::Make(ModuleLang::class)->Get('error'));
		}
	}
	/**
	 * Удаление записи со стены
	 */
	public function EventWallRemove() {
		/**
		 * Устанавливаем формат Ajax ответа
		 */
		LS::Make(ModuleViewer::class)->SetResponseAjax('json');
		/**
		 * Пользователь авторизован?
		 */
		if (!$this->oUserCurrent) {
			return parent::EventNotFound();
		}
		if (!$this->CheckUserProfile()) {
			return parent::EventNotFound();
		}
		/**
		 * Получаем запись
		 */
		if (!($oWall=LS::Make(ModuleWall::class)->GetWallById(getRequestStr('iId')))) {
			return parent::EventNotFound();
		}
		/**
		 * Если разрешено удаление - удаляем
		 */
		if ($oWall->isAllowDelete()) {
			LS::Make(ModuleWall::class)->DeleteWall($oWall);
			return;
		}
		return parent::EventNotFound();
	}
	/**
	 * Ajax подгрузка сообщений стены
	 */
	public function EventWallLoad() {
		/**
		 * Устанавливаем формат Ajax ответа
		 */
		LS::Make(ModuleViewer::class)->SetResponseAjax('json');
		if (!$this->CheckUserProfile()) {
			return parent::EventNotFound();
		}
		/**
		 * Формируем фильтр для запроса к БД
		 */
		$aFilter=array(
			'wall_user_id'=>$this->oUserProfile->getId(),
			'pid'=>null
		);
		if (is_numeric(getRequest('iIdLess'))) {
			$aFilter['id_less']=getRequest('iIdLess');
		} elseif (is_numeric(getRequest('iIdMore'))) {
			$aFilter['id_more']=getRequest('iIdMore');
		} else {
			LS::Make(ModuleMessage::class)->AddError(LS::Make(ModuleLang::class)->Get('error'));
			return;
		}
		/**
		 * Получаем сообщения и формируем ответ
		 */
		$aWall=LS::Make(ModuleWall::class)->GetWall($aFilter,array('id'=>'desc'),1,Config::Get('module.wall.per_page'));
		LS::Make(ModuleViewer::class)->Assign('aWall',$aWall['collection']);
		LS::Make(ModuleViewer::class)->Assign('oUserCurrent',$this->oUserCurrent); // хак, т.к. к этому моменту текущий юзер не загружен в шаблон
		LS::Make(ModuleViewer::class)->AssignAjax('sText', LS::Make(ModuleViewer::class)->Fetch('actions/ActionProfile/wall_items.tpl'));
		LS::Make(ModuleViewer::class)->AssignAjax('iCountWall',$aWall['count']);
		LS::Make(ModuleViewer::class)->AssignAjax('iCountWallReturn',count($aWall['collection']));
	}
	/**
	 * Подгрузка ответов на стене к сообщению
	 */
	public function EventWallLoadReply() {
		/**
		 * Устанавливаем формат Ajax ответа
		 */
		LS::Make(ModuleViewer::class)->SetResponseAjax('json');
		if (!$this->CheckUserProfile()) {
			return parent::EventNotFound();
		}
		if (!($oWall=LS::Make(ModuleWall::class)->GetWallById(getRequestStr('iPid'))) or $oWall->getPid()) {
			return parent::EventNotFound();
		}
		/**
		 * Формируем фильтр для запроса к БД
		 */
		$aFilter=array(
			'wall_user_id'=>$this->oUserProfile->getId(),
			'pid'=>$oWall->getId()
		);
		if (is_numeric(getRequest('iIdLess'))) {
			$aFilter['id_less']=getRequest('iIdLess');
		} elseif (is_numeric(getRequest('iIdMore'))) {
			$aFilter['id_more']=getRequest('iIdMore');
		} else {
			LS::Make(ModuleMessage::class)->AddError(LS::Make(ModuleLang::class)->Get('error'));
			return;
		}
		/**
		 * Получаем сообщения и формируем ответ
		 * Необходимо вернуть все ответы, но ставим "разумное" ограничение
		 */
		$aWall=LS::Make(ModuleWall::class)->GetWall($aFilter,array('id'=>'asc'),1,300);
		LS::Make(ModuleViewer::class)->Assign('aReplyWall',$aWall['collection']);
		LS::Make(ModuleViewer::class)->AssignAjax('sText', LS::Make(ModuleViewer::class)->Fetch('actions/ActionProfile/wall_items_reply.tpl'));
		LS::Make(ModuleViewer::class)->AssignAjax('iCountWall',$aWall['count']);
		LS::Make(ModuleViewer::class)->AssignAjax('iCountWallReturn',count($aWall['collection']));
	}
	/**
	 * Сохраняет заметку о пользователе
	 */
	public function EventAjaxNoteSave() {
		/**
		 * Устанавливаем формат Ajax ответа
		 */
		LS::Make(ModuleViewer::class)->SetResponseAjax('json');
		/**
		 * Пользователь авторизован?
		 */
		if (!$this->oUserCurrent) {
			return parent::EventNotFound();
		}
		/**
		 * Создаем заметку и проводим валидацию
		 */
		$oNote= new ModuleUser_EntityNote();
		$oNote->setTargetUserId(getRequestStr('iUserId'));
		$oNote->setUserId($this->oUserCurrent->getId());
		$oNote->setText(getRequestStr('text'));

		if ($oNote->_Validate()) {
			/**
			 * Экранируем текст и добавляем запись в БД
			 */
			$oNote->setText(htmlspecialchars(strip_tags($oNote->getText())));
			if (LS::Make(ModuleUser::class)->SaveNote($oNote)) {
				LS::Make(ModuleViewer::class)->AssignAjax('sText',$oNote->getText());
			} else {
				LS::Make(ModuleMessage::class)->AddError(LS::Make(ModuleLang::class)->Get('user_note_save_error'),LS::Make(ModuleLang::class)->Get('error'));
			}
		} else {
			LS::Make(ModuleMessage::class)->AddError($oNote->_getValidateError(),LS::Make(ModuleLang::class)->Get('error'));
		}
	}
	/**
	 * Удаляет заметку о пользователе
	 */
	public function EventAjaxNoteRemove() {
		/**
		 * Устанавливаем формат Ajax ответа
		 */
		LS::Make(ModuleViewer::class)->SetResponseAjax('json');
		if (!$this->oUserCurrent) {
			return parent::EventNotFound();
		}

		if (!($oUserTarget=LS::Make(ModuleUser::class)->GetUserById(getRequestStr('iUserId')))) {
			return parent::EventNotFound();
		}
		if (!($oNote=LS::Make(ModuleUser::class)->GetUserNote($oUserTarget->getId(),$this->oUserCurrent->getId()))) {
			return parent::EventNotFound();
		}
		LS::Make(ModuleUser::class)->DeleteUserNoteById($oNote->getId());
	}
	/**
	 * Список созданных заметок
	 */
	public function EventCreatedNotes() {
		if (!$this->CheckUserProfile()) {
			return parent::EventNotFound();
		}
		$this->sMenuSubItemSelect='notes';
		/**
		 * Заметки может читать только сам пользователь
		 */
		if (!$this->oUserCurrent or $this->oUserCurrent->getId()!=$this->oUserProfile->getId()) {
			return parent::EventNotFound();
		}
		/**
		 * Передан ли номер страницы
		 */
		$iPage=$this->GetParamEventMatch(2,2) ? $this->GetParamEventMatch(2,2) : 1;
		/**
		 * Получаем список заметок
		 */
		$aResult=LS::Make(ModuleUser::class)->GetUserNotesByUserId($this->oUserProfile->getId(),$iPage,Config::Get('module.user.usernote_per_page'));
		$aNotes=$aResult['collection'];
		/**
		 * Формируем постраничность
		 */
		$aPaging=LS::Make(ModuleViewer::class)->MakePaging($aResult['count'],$iPage,Config::Get('module.user.usernote_per_page'),Config::Get('pagination.pages.count'),$this->oUserProfile->getUserWebPath().'created/notes');
		/**
		 * Загружаем переменные в шаблон
		 */
		LS::Make(ModuleViewer::class)->Assign('aPaging',$aPaging);
		LS::Make(ModuleViewer::class)->Assign('aNotes',$aNotes);
		LS::Make(ModuleViewer::class)->AddHtmlTitle(LS::Make(ModuleLang::class)->Get('user_menu_profile').' '.$this->oUserProfile->getLogin());
		LS::Make(ModuleViewer::class)->AddHtmlTitle(LS::Make(ModuleLang::class)->Get('user_menu_profile_notes'));
		/**
		 * Устанавливаем шаблон вывода
		 */
		$this->SetTemplateAction('created_notes');
	}
	/**
	 * Добавление пользователя в друзья, по отправленной заявке
	 */
	public function EventFriendOffer() {
		require_once './lib/XXTEA/encrypt.php';
		/**
		 * Из реквеста дешефруем ID польователя
		 */
		$sUserId=xxtea_decrypt(base64_decode(rawurldecode(getRequestStr('code'))), Config::Get('module.talk.encrypt'));
		if (!$sUserId) {
			return $this->EventNotFound();
		}
		list($sUserId,)=explode('_',$sUserId,2);

		$sAction=$this->GetParam(0);
		/**
		 * Получаем текущего пользователя
		 */
		if(!LS::Make(ModuleUser::class)->IsAuthorization()) {
			return $this->EventNotFound();
		}
		$this->oUserCurrent = LS::Make(ModuleUser::class)->GetUserCurrent();
		/**
		 * Получаем объект пользователя приславшего заявку,
		 * если пользователь не найден, переводим в раздел сообщений (Talk) -
		 * так как пользователь мог перейти сюда либо из talk-сообщений,
		 * либо из e-mail письма-уведомления
		 */
		if(!$oUser=LS::Make(ModuleUser::class)->GetUserById($sUserId)) {
			LS::Make(ModuleMessage::class)->AddError(LS::Make(ModuleLang::class)->Get('user_not_found'),LS::Make(ModuleLang::class)->Get('error'),true);
			Router::Location(Router::GetPath('talk'));
			return ;
		}
		/**
		 * Получаем связь дружбы из базы данных.
		 * Если связь не найдена либо статус отличен от OFFER,
		 * переходим в раздел Talk и возвращаем сообщение об ошибке
		 */
		$oFriend=LS::Make(ModuleUser::class)->GetFriend($this->oUserCurrent->getId(),$oUser->getId(),0);
		if(!$oFriend
			|| !in_array(
				$oFriend->getFriendStatus(),
				array(
					ModuleUser::USER_FRIEND_OFFER+ModuleUser::USER_FRIEND_NULL,
				)
			)
		) {
			$sMessage=($oFriend)
				? LS::Make(ModuleLang::class)->Get('user_friend_offer_already_done')
				: LS::Make(ModuleLang::class)->Get('user_friend_offer_not_found');
			LS::Make(ModuleMessage::class)->AddError($sMessage,LS::Make(ModuleLang::class)->Get('error'),true);

			Router::Location(Router::GetPath('talk'));
			return ;
		}
		/**
		 * Устанавливаем новый статус связи
		 */
		$oFriend->setStatusTo(
			($sAction=='accept')
				? ModuleUser::USER_FRIEND_ACCEPT
				: ModuleUser::USER_FRIEND_REJECT
		);

		if (LS::Make(ModuleUser::class)->UpdateFriend($oFriend)) {
			$sMessage=($sAction=='accept')
				? LS::Make(ModuleLang::class)->Get('user_friend_add_ok')
				: LS::Make(ModuleLang::class)->Get('user_friend_offer_reject');

			LS::Make(ModuleMessage::class)->AddNoticeSingle($sMessage,LS::Make(ModuleLang::class)->Get('attention'),true);
			$this->NoticeFriendOffer($oUser,$sAction);
		} else {
			LS::Make(ModuleMessage::class)->AddErrorSingle(
				LS::Make(ModuleLang::class)->Get('system_error'),
				LS::Make(ModuleLang::class)->Get('error'),
				true
			);
		}
		Router::Location(Router::GetPath('talk'));
	}
	/**
	 * Подтверждение заявки на добавления в друзья
	 */
	public function EventAjaxFriendAccept() {
		/**
		 * Устанавливаем формат Ajax ответа
		 */
		LS::Make(ModuleViewer::class)->SetResponseAjax('json');
		$sUserId=getRequestStr('idUser',null,'post');
		/**
		 * Если пользователь не авторизирован, возвращаем ошибку
		 */
		if (!LS::Make(ModuleUser::class)->IsAuthorization()) {
			LS::Make(ModuleMessage::class)->AddErrorSingle(
				LS::Make(ModuleLang::class)->Get('need_authorization'),
				LS::Make(ModuleLang::class)->Get('error')
			);
			return;
		}
		$this->oUserCurrent=LS::Make(ModuleUser::class)->GetUserCurrent();
		/**
		 * При попытке добавить в друзья себя, возвращаем ошибку
		 */
		if ($this->oUserCurrent->getId()==$sUserId) {
			LS::Make(ModuleMessage::class)->AddErrorSingle(
				LS::Make(ModuleLang::class)->Get('user_friend_add_self'),
				LS::Make(ModuleLang::class)->Get('error')
			);
			return;
		}
		/**
		 * Если пользователь не найден, возвращаем ошибку
		 */
		if( !$oUser=LS::Make(ModuleUser::class)->GetUserById($sUserId) ) {
			LS::Make(ModuleMessage::class)->AddErrorSingle(
				LS::Make(ModuleLang::class)->Get('user_not_found'),
				LS::Make(ModuleLang::class)->Get('error')
			);
			return;
		}
		$this->oUserProfile=$oUser;
		/**
		 * Получаем статус дружбы между пользователями
		 */
		$oFriend=LS::Make(ModuleUser::class)->GetFriend($oUser->getId(),$this->oUserCurrent->getId());
		/**
		 * При попытке потдвердить ранее отклоненную заявку,
		 * проверяем, чтобы изменяющий был принимающей стороной
		 */
		if($oFriend
			&& ($oFriend->getStatusFrom()==ModuleUser::USER_FRIEND_OFFER||$oFriend->getStatusFrom()==ModuleUser::USER_FRIEND_ACCEPT)
			&& ($oFriend->getStatusTo()==ModuleUser::USER_FRIEND_REJECT||$oFriend->getStatusTo()==ModuleUser::USER_FRIEND_NULL)
			&& $oFriend->getUserTo()==$this->oUserCurrent->getId()) {
			/**
			 * Меняем статус с отвергнутое, на акцептованное
			 */
			$oFriend->setStatusByUserId(ModuleUser::USER_FRIEND_ACCEPT,$this->oUserCurrent->getId());
			if(LS::Make(ModuleUser::class)->UpdateFriend($oFriend)) {
				LS::Make(ModuleMessage::class)->AddNoticeSingle(LS::Make(ModuleLang::class)->Get('user_friend_add_ok'),LS::Make(ModuleLang::class)->Get('attention'));
				$this->NoticeFriendOffer($oUser,'accept');
				/**
				 * Добавляем событие в ленту
				 */
				LS::Make(ModuleStream::class)->write($oFriend->getUserFrom(), 'add_friend', $oFriend->getUserTo());
				LS::Make(ModuleStream::class)->write($oFriend->getUserTo(), 'add_friend', $oFriend->getUserFrom());
				/**
				 * Добавляем пользователей к друг другу в ленту активности
				 */
				LS::Make(ModuleStream::class)->subscribeUser($oFriend->getUserFrom(), $oFriend->getUserTo());
				LS::Make(ModuleStream::class)->subscribeUser($oFriend->getUserTo(), $oFriend->getUserFrom());

				$oViewerLocal=$this->GetViewerLocal();
				$oViewerLocal->Assign('oUserFriend',$oFriend);
				LS::Make(ModuleViewer::class)->AssignAjax('sToggleText',$oViewerLocal->Fetch("actions/ActionProfile/friend_item.tpl"));

			} else {
				LS::Make(ModuleMessage::class)->AddErrorSingle(
					LS::Make(ModuleLang::class)->Get('system_error'),
					LS::Make(ModuleLang::class)->Get('error')
				);
			}
			return;
		}

		LS::Make(ModuleMessage::class)->AddErrorSingle(
			LS::Make(ModuleLang::class)->Get('system_error'),
			LS::Make(ModuleLang::class)->Get('error')
		);
		return;
	}
	/**
	 * Отправляет пользователю Talk уведомление о принятии или отклонении его заявки
	 *
	 * @param ModuleUser_EntityUser $oUser
	 * @param string $sAction
	 */
	protected function NoticeFriendOffer($oUser,$sAction) {
		/**
		 * Проверяем допустимость действия
		 */
		if(!in_array($sAction,array('accept','reject'))) {
			return false;
		}
		/**
		 * Проверяем настройки (нужно ли отправлять уведомление)
		 */
		if(!Config::Get("module.user.friend_notice.{$sAction}")) {
			return false;
		}

		$sTitle=LS::Make(ModuleLang::class)->Get("user_friend_{$sAction}_notice_title");
		$sText=LS::Make(ModuleLang::class)->Get(
			"user_friend_{$sAction}_notice_text",
			array(
				'login'=>$this->oUserCurrent->getLogin(),
			)
		);
		$oTalk=LS::Make(ModuleTalk::class)->SendTalk($sTitle,$sText,$this->oUserCurrent,array($oUser),false,false);
		LS::Make(ModuleTalk::class)->DeleteTalkUserByArray($oTalk->getId(),$this->oUserCurrent->getId());
	}
	/**
	 * Обработка Ajax добавления в друзья
	 */
	public function EventAjaxFriendAdd() {
		/**
		 * Устанавливаем формат Ajax ответа
		 */
		LS::Make(ModuleViewer::class)->SetResponseAjax('json');
		$sUserId=getRequestStr('idUser');
		$sUserText=getRequestStr('userText','');
		/**
		 * Если пользователь не авторизирован, возвращаем ошибку
		 */
		if (!LS::Make(ModuleUser::class)->IsAuthorization()) {
			LS::Make(ModuleMessage::class)->AddErrorSingle(
				LS::Make(ModuleLang::class)->Get('need_authorization'),
				LS::Make(ModuleLang::class)->Get('error')
			);
			return;
		}
		$this->oUserCurrent=LS::Make(ModuleUser::class)->GetUserCurrent();
		/**
		 * При попытке добавить в друзья себя, возвращаем ошибку
		 */
		if ($this->oUserCurrent->getId()==$sUserId) {
			LS::Make(ModuleMessage::class)->AddErrorSingle(
				LS::Make(ModuleLang::class)->Get('user_friend_add_self'),
				LS::Make(ModuleLang::class)->Get('error')
			);
			return;
		}
		/**
		 * Если пользователь не найден, возвращаем ошибку
		 */
		if( !$oUser=LS::Make(ModuleUser::class)->GetUserById($sUserId) ) {
			LS::Make(ModuleMessage::class)->AddErrorSingle(
				LS::Make(ModuleLang::class)->Get('user_not_found'),
				LS::Make(ModuleLang::class)->Get('error')
			);
			return;
		}
		$this->oUserProfile=$oUser;
		/**
		 * Получаем статус дружбы между пользователями
		 */
		$oFriend=LS::Make(ModuleUser::class)->GetFriend($oUser->getId(),$this->oUserCurrent->getId());
		/**
		 * Если связи ранее не было в базе данных, добавляем новую
		 */
		if( !$oFriend ) {
			$this->SubmitAddFriend($oUser,$sUserText,$oFriend);
			return;
		}
		/**
		 * Если статус связи соответствует статусам отправленной и акцептованной заявки,
		 * то предупреждаем что этот пользователь уже является нашим другом
		 */
		if($oFriend->getFriendStatus()==ModuleUser::USER_FRIEND_OFFER + ModuleUser::USER_FRIEND_ACCEPT) {
			LS::Make(ModuleMessage::class)->AddErrorSingle(
				LS::Make(ModuleLang::class)->Get('user_friend_already_exist'),
				LS::Make(ModuleLang::class)->Get('error')
			);
			return;
		}
		/**
		 * Если пользователь ранее отклонил нашу заявку,
		 * возвращаем сообщение об ошибке
		 */
		if($oFriend->getUserFrom()==$this->oUserCurrent->getId()
			&& $oFriend->getStatusTo()==ModuleUser::USER_FRIEND_REJECT ) {
			LS::Make(ModuleMessage::class)->AddErrorSingle(
				LS::Make(ModuleLang::class)->Get('user_friend_offer_reject'),
				LS::Make(ModuleLang::class)->Get('error')
			);
			return;
		}
		/**
		 * Если дружба была удалена, то проверяем кто ее удалил
		 * и разрешаем восстановить только удалившему
		 */
		if($oFriend->getFriendStatus()>ModuleUser::USER_FRIEND_DELETE
			&& $oFriend->getFriendStatus()<ModuleUser::USER_FRIEND_REJECT) {
			/**
			 * Определяем статус связи текущего пользователя
			 */
			$iStatusCurrent	= $oFriend->getStatusByUserId($this->oUserCurrent->getId());

			if($iStatusCurrent==ModuleUser::USER_FRIEND_DELETE) {
				/**
				 * Меняем статус с удаленного, на акцептованное
				 */
				$oFriend->setStatusByUserId(ModuleUser::USER_FRIEND_ACCEPT,$this->oUserCurrent->getId());
				if(LS::Make(ModuleUser::class)->UpdateFriend($oFriend)) {
					/**
					 * Добавляем событие в ленту
					 */
					LS::Make(ModuleStream::class)->write($oFriend->getUserFrom(), 'add_friend', $oFriend->getUserTo());
					LS::Make(ModuleStream::class)->write($oFriend->getUserTo(), 'add_friend', $oFriend->getUserFrom());
					LS::Make(ModuleMessage::class)->AddNoticeSingle(LS::Make(ModuleLang::class)->Get('user_friend_add_ok'),LS::Make(ModuleLang::class)->Get('attention'));

					$oViewerLocal=$this->GetViewerLocal();
					$oViewerLocal->Assign('oUserFriend',$oFriend);
					LS::Make(ModuleViewer::class)->AssignAjax('sToggleText',$oViewerLocal->Fetch("actions/ActionProfile/friend_item.tpl"));

				} else {
					LS::Make(ModuleMessage::class)->AddErrorSingle(
						LS::Make(ModuleLang::class)->Get('system_error'),
						LS::Make(ModuleLang::class)->Get('error')
					);
				}
				return;
			} else {
				LS::Make(ModuleMessage::class)->AddErrorSingle(
					LS::Make(ModuleLang::class)->Get('user_friend_add_deleted'),
					LS::Make(ModuleLang::class)->Get('error')
				);
				return;
			}
		}
	}
	/**
	 * Функция создает локальный объект вьювера для рендеринга html-объектов в ajax запросах
	 *
	 * @return ModuleViewer
	 */
	protected function GetViewerLocal() {
		/**
		 * Получаем HTML код inject-объекта
		 */
		$oViewerLocal=LS::Make(ModuleViewer::class)->GetLocalViewer();
		$oViewerLocal->Assign('oUserCurrent',$this->oUserCurrent);
		$oViewerLocal->Assign('oUserProfile',$this->oUserProfile);

		$oViewerLocal->Assign('USER_FRIEND_NULL',ModuleUser::USER_FRIEND_NULL);
		$oViewerLocal->Assign('USER_FRIEND_OFFER',ModuleUser::USER_FRIEND_OFFER);
		$oViewerLocal->Assign('USER_FRIEND_ACCEPT',ModuleUser::USER_FRIEND_ACCEPT);
		$oViewerLocal->Assign('USER_FRIEND_REJECT',ModuleUser::USER_FRIEND_REJECT);
		$oViewerLocal->Assign('USER_FRIEND_DELETE',ModuleUser::USER_FRIEND_DELETE);

		return $oViewerLocal;
	}
	/**
	 * Обработка добавления в друзья
	 *
	 * @param $oUser
	 * @param $sUserText
	 * @param null $oFriend
	 * @return bool
	 */
	protected function SubmitAddFriend($oUser,$sUserText,$oFriend=null) {
		/**
		 * Ограничения на добавления в друзья, т.к. приглашение отправляется в личку, то и ограничиваем по ней
		 */
		if (!LS::Make(ModuleACL::class)->CanSendTalkTime($this->oUserCurrent)) {
			LS::Make(ModuleMessage::class)->AddErrorSingle(LS::Make(ModuleLang::class)->Get('user_friend_add_time_limit'),LS::Make(ModuleLang::class)->Get('error'));
			return false;
		}
		/**
		 * Обрабатываем текст заявки
		 */
		$sUserText=LS::Make(ModuleText::class)->Parser($sUserText);
		/**
		 * Создаем связь с другом
		 */
		$oFriendNew = new ModuleUser_EntityFriend();
		$oFriendNew->setUserTo($oUser->getId());
		$oFriendNew->setUserFrom($this->oUserCurrent->getId());
		// Добавляем заявку в друзья
		$oFriendNew->setStatusFrom(ModuleUser::USER_FRIEND_OFFER);
		$oFriendNew->setStatusTo(ModuleUser::USER_FRIEND_NULL);

		$bStateError=($oFriend)
			? !LS::Make(ModuleUser::class)->UpdateFriend($oFriendNew)
			: !LS::Make(ModuleUser::class)->AddFriend($oFriendNew);

		if ( !$bStateError ) {
			LS::Make(ModuleMessage::class)->AddNoticeSingle(LS::Make(ModuleLang::class)->Get('user_friend_offer_send'),LS::Make(ModuleLang::class)->Get('attention'));

			$sTitle=LS::Make(ModuleLang::class)->Get(
				'user_friend_offer_title',
				array(
					'login'=>$this->oUserCurrent->getLogin(),
					'friend'=>$oUser->getLogin()
				)
			);

			require_once './lib/XXTEA/encrypt.php';
			$sCode=$this->oUserCurrent->getId().'_'.$oUser->getId();
			$sCode=rawurlencode(base64_encode(xxtea_encrypt($sCode, Config::Get('module.talk.encrypt'))));

			$aPath=array(
				'accept'=>Router::GetPath('profile').'friendoffer/accept/?code='.$sCode,
				'reject'=>Router::GetPath('profile').'friendoffer/reject/?code='.$sCode
			);

			$sText=LS::Make(ModuleLang::class)->Get(
				'user_friend_offer_text',
				array(
					'login'=>$this->oUserCurrent->getLogin(),
					'accept_path'=>$aPath['accept'],
					'reject_path'=>$aPath['reject'],
					'user_text'=>$sUserText
				)
			);
			$oTalk=LS::Make(ModuleTalk::class)->SendTalk($sTitle,$sText,$this->oUserCurrent,array($oUser),false,false);
			/**
			 * Отправляем пользователю заявку
			 */
			LS::Make(ModuleNotify::class)->SendUserFriendNew(
				$oUser,$this->oUserCurrent,$sUserText,
				Router::GetPath('talk').'read/'.$oTalk->getId().'/'
			);
			/**
			 * Удаляем отправляющего юзера из переписки
			 */
			LS::Make(ModuleTalk::class)->DeleteTalkUserByArray($oTalk->getId(),$this->oUserCurrent->getId());
		} else {
			LS::Make(ModuleMessage::class)->AddErrorSingle(LS::Make(ModuleLang::class)->Get('system_error'),LS::Make(ModuleLang::class)->Get('error'));
		}

		$oViewerLocal=$this->GetViewerLocal();
		$oViewerLocal->Assign('oUserFriend',$oFriendNew);
		LS::Make(ModuleViewer::class)->AssignAjax('sToggleText',$oViewerLocal->Fetch("actions/ActionProfile/friend_item.tpl"));
	}
	/**
	 * Удаление пользователя из друзей
	 */
	public function EventAjaxFriendDelete() {
		/**
		 * Устанавливаем формат Ajax ответа
		 */
		LS::Make(ModuleViewer::class)->SetResponseAjax('json');
		$sUserId=getRequestStr('idUser',null,'post');
		/**
		 * Если пользователь не авторизирован, возвращаем ошибку
		 */
		if (!LS::Make(ModuleUser::class)->IsAuthorization()) {
			LS::Make(ModuleMessage::class)->AddErrorSingle(
				LS::Make(ModuleLang::class)->Get('need_authorization'),
				LS::Make(ModuleLang::class)->Get('error')
			);
			return;
		}
		$this->oUserCurrent=LS::Make(ModuleUser::class)->GetUserCurrent();
		/**
		 * При попытке добавить в друзья себя, возвращаем ошибку
		 */
		if ($this->oUserCurrent->getId()==$sUserId) {
			LS::Make(ModuleMessage::class)->AddErrorSingle(
				LS::Make(ModuleLang::class)->Get('user_friend_add_self'),
				LS::Make(ModuleLang::class)->Get('error')
			);
			return;
		}
		/**
		 * Если пользователь не найден, возвращаем ошибку
		 */
		if( !$oUser=LS::Make(ModuleUser::class)->GetUserById($sUserId) ) {
			LS::Make(ModuleMessage::class)->AddErrorSingle(
				LS::Make(ModuleLang::class)->Get('user_friend_del_no'),
				LS::Make(ModuleLang::class)->Get('error')
			);
			return;
		}
		$this->oUserProfile=$oUser;
		/**
		 * Получаем статус дружбы между пользователями.
		 * Если статус не определен, или отличается от принятой заявки,
		 * возвращаем ошибку
		 */
		$oFriend=LS::Make(ModuleUser::class)->GetFriend($oUser->getId(),$this->oUserCurrent->getId());
		$aAllowedFriendStatus = array(ModuleUser::USER_FRIEND_ACCEPT+ModuleUser::USER_FRIEND_OFFER,ModuleUser::USER_FRIEND_ACCEPT+ModuleUser::USER_FRIEND_ACCEPT);
		if(!$oFriend || !in_array($oFriend->getFriendStatus(),$aAllowedFriendStatus)) {
			LS::Make(ModuleMessage::class)->AddErrorSingle(
				LS::Make(ModuleLang::class)->Get('user_friend_del_no'),
				LS::Make(ModuleLang::class)->Get('error')
			);
			return;
		}
		/**
		 * Удаляем из друзей
		 */
		if( LS::Make(ModuleUser::class)->DeleteFriend($oFriend) ) {
			LS::Make(ModuleMessage::class)->AddNoticeSingle(LS::Make(ModuleLang::class)->Get('user_friend_del_ok'),LS::Make(ModuleLang::class)->Get('attention'));

			$oViewerLocal=$this->GetViewerLocal();
			$oViewerLocal->Assign('oUserFriend',$oFriend);
			LS::Make(ModuleViewer::class)->AssignAjax('sToggleText',$oViewerLocal->Fetch("actions/ActionProfile/friend_item.tpl"));

			/**
			 * Отправляем пользователю сообщение об удалении дружеской связи
			 */
			if(Config::Get('module.user.friend_notice.delete')) {
				$sText=LS::Make(ModuleLang::class)->Get(
					'user_friend_del_notice_text',
					array(
						'login'=>$this->oUserCurrent->getLogin(),
					)
				);
				$oTalk=LS::Make(ModuleTalk::class)->SendTalk(
					LS::Make(ModuleLang::class)->Get('user_friend_del_notice_title'),
					$sText,$this->oUserCurrent,
					array($oUser),false,false
				);
				LS::Make(ModuleTalk::class)->DeleteTalkUserByArray($oTalk->getId(),$this->oUserCurrent->getId());
			}
			return;
		} else {
			LS::Make(ModuleMessage::class)->AddErrorSingle(LS::Make(ModuleLang::class)->Get('system_error'),LS::Make(ModuleLang::class)->Get('error'));
			return;
		}
	}
	/**
	 * Обработка подтверждения старого емайла при его смене
	 */
	public function EventChangemailConfirmFrom() {
		if (!($oChangemail=LS::Make(ModuleUser::class)->GetUserChangemailByCodeFrom($this->GetParamEventMatch(1,0)))) {
			return parent::EventNotFound();
		}

		if ($oChangemail->getConfirmFrom() or strtotime($oChangemail->getDateExpired())<time()) {
			return parent::EventNotFound();
		}

		$oChangemail->setConfirmFrom(1);
		LS::Make(ModuleUser::class)->UpdateUserChangemail($oChangemail);

		/**
		 * Отправляем уведомление
		 */
		$oUser=LS::Make(ModuleUser::class)->GetUserById($oChangemail->getUserId());
		LS::Make(ModuleNotify::class)->Send($oChangemail->getMailTo(),
						   'notify.user_changemail_to.tpl',
						   LS::Make(ModuleLang::class)->Get('notify_subject_user_changemail'),
						   array(
							   'oUser' => $oUser,
							   'oChangemail' => $oChangemail,
						   ));

		LS::Make(ModuleViewer::class)->Assign('sText',LS::Make(ModuleLang::class)->Get('settings_profile_mail_change_to_notice'));
		$this->SetTemplateAction('changemail_confirm');

	}
	/**
	 * Обработка подтверждения нового емайла при смене старого
	 */
	public function EventChangemailConfirmTo() {
		if (!($oChangemail=LS::Make(ModuleUser::class)->GetUserChangemailByCodeTo($this->GetParamEventMatch(1,0)))) {
			return parent::EventNotFound();
		}

		if (!$oChangemail->getConfirmFrom() or $oChangemail->getConfirmTo() or strtotime($oChangemail->getDateExpired())<time()) {
			return parent::EventNotFound();
		}

		$oChangemail->setConfirmTo(1);
		$oChangemail->setDateUsed(date("Y-m-d H:i:s"));
		LS::Make(ModuleUser::class)->UpdateUserChangemail($oChangemail);

		$oUser=LS::Make(ModuleUser::class)->GetUserById($oChangemail->getUserId());
		$oUser->setMail($oChangemail->getMailTo());
		LS::Make(ModuleUser::class)->Update($oUser);


		LS::Make(ModuleViewer::class)->Assign('sText',LS::Make(ModuleLang::class)->Get('settings_profile_mail_change_ok',array('mail'=>htmlspecialchars($oChangemail->getMailTo()))));
		$this->SetTemplateAction('changemail_confirm');
	}
	/**
	 * Выполняется при завершении работы экшена
	 */
	public function EventShutdown() {
		if (!$this->oUserProfile)	 {
			return ;
		}
		/**
		 * Загружаем в шаблон необходимые переменные
		 */
		LS::Make(ModuleLang::class)->AddLangJs(array(
            'ignore_user_talks', 'disignore_user_talks',
            'ignore_user_ok_talk', 'disignore_user_ok_talk'
        ));
		$oUserCurrent = LS::Make(ModuleUser::class)->GetUserCurrent();
		if ($oUserCurrent) {
            $aForbidIgnore = LS::Make(ModuleUser::class)->GetForbidIgnoredUsers();
            if (in_array($this->oUserProfile->getId(), $aForbidIgnore)) {
                LS::Make(ModuleViewer::class)->Assign('bForbidIgnore', true);
            } else if ($oUserCurrent->getId() != $this->oUserProfile->getId()) {
                $bIgnoredTopics = LS::Make(ModuleUser::class)->IsUserIgnoredByUser($oUserCurrent->getId(), $this->oUserProfile->getId(), ModuleUser::TYPE_IGNORE_TOPICS);
                $bIgnoredComments = LS::Make(ModuleUser::class)->IsUserIgnoredByUser($oUserCurrent->getId(), $this->oUserProfile->getId(), ModuleUser::TYPE_IGNORE_COMMENTS);

                LS::Make(ModuleViewer::class)->Assign('bIgnoredTopics', $bIgnoredTopics);
                LS::Make(ModuleViewer::class)->Assign('bIgnoredComments', $bIgnoredComments);
            }

            $aUserBlacklist = LS::Make(ModuleTalk::class)->GetBlacklistByUserId($oUserCurrent->getId());
            if (isset($aUserBlacklist[$this->oUserProfile->getId()])) {
                $bIgnoredTalks = 1;
            } else {
                $bIgnoredTalks = 0;
            }
            LS::Make(ModuleViewer::class)->Assign('bIgnoredTalks', $bIgnoredTalks);
        }

		$aBlogUsers=LS::Make(ModuleBlog::class)->GetBlogUsersByUserId($this->oUserProfile->getId(),ModuleBlog::BLOG_USER_ROLE_USER);
		$aBlogModerators=LS::Make(ModuleBlog::class)->GetBlogUsersByUserId($this->oUserProfile->getId(),ModuleBlog::BLOG_USER_ROLE_MODERATOR);
		$aBlogAdministrators=LS::Make(ModuleBlog::class)->GetBlogUsersByUserId($this->oUserProfile->getId(),ModuleBlog::BLOG_USER_ROLE_ADMINISTRATOR);
		/**
		 * Получаем список блогов которые создал юзер
		 */
		$aBlogsOwner=LS::Make(ModuleBlog::class)->GetBlogsByOwnerId($this->oUserProfile->getId());
		/**
		 * Получаем список контактов
		 */
		$aUserFields = LS::Make(ModuleUser::class)->getUserFieldsValues($this->oUserProfile->getId());
		/**
		 * Вызов хуков
		 */
		LS::Make(ModuleHook::class)->Run('profile_whois_show',array("oUserProfile"=>$this->oUserProfile));
		/**
		 * Загружаем переменные в шаблон
		 */
		LS::Make(ModuleViewer::class)->Assign('aBlogUsers',$aBlogUsers);
		LS::Make(ModuleViewer::class)->Assign('aBlogModerators',$aBlogModerators);
		LS::Make(ModuleViewer::class)->Assign('aBlogAdministrators',$aBlogAdministrators);
		LS::Make(ModuleViewer::class)->Assign('aBlogsOwner',$aBlogsOwner);
		LS::Make(ModuleViewer::class)->Assign('aUserFields',$aUserFields);

		$iCountTopicFavourite=LS::Make(ModuleTopic::class)->GetCountTopicsFavouriteByUserId($this->oUserProfile->getId());
		$iCountTopicUser=LS::Make(ModuleTopic::class)->GetCountTopicsPersonalByUser($this->oUserProfile->getId(),1);
		$iCountCommentUser=LS::Make(ModuleComment::class)->GetCountCommentsByUserId($this->oUserProfile->getId(),'topic');
		$iCountCommentFavourite=LS::Make(ModuleComment::class)->GetCountCommentsFavouriteByUserId($this->oUserProfile->getId());
		$iCountNoteUser=LS::Make(ModuleUser::class)->GetCountUserNotesByUserId($this->oUserProfile->getId());

		LS::Make(ModuleViewer::class)->Assign('oUserProfile',$this->oUserProfile);
		LS::Make(ModuleViewer::class)->Assign('iCountTopicUser',$iCountTopicUser);
		LS::Make(ModuleViewer::class)->Assign('iCountCommentUser', $iCountCommentUser);
		LS::Make(ModuleViewer::class)->Assign('iCountTopicFavourite',$iCountTopicFavourite);
		LS::Make(ModuleViewer::class)->Assign('iCountCommentFavourite',$iCountCommentFavourite);
		LS::Make(ModuleViewer::class)->Assign('iCountNoteUser',$iCountNoteUser);
		LS::Make(ModuleViewer::class)->Assign('iCountWallUser',LS::Make(ModuleWall::class)->GetCountWall(array('wall_user_id'=>$this->oUserProfile->getId(),'pid'=>null)));
		/**
		 * Общее число публикация и избранного
		 */
		LS::Make(ModuleViewer::class)->Assign('iCountCreated',(($this->oUserCurrent and $this->oUserCurrent->getId()==$this->oUserProfile->getId()) ? $iCountNoteUser : 0) +$iCountTopicUser+$iCountCommentUser);
		LS::Make(ModuleViewer::class)->Assign('iCountFavourite',$iCountCommentFavourite+$iCountTopicFavourite);
		/**
		 * Заметка текущего пользователя о юзере
		 */
		if ($this->oUserCurrent) {
			LS::Make(ModuleViewer::class)->Assign('oUserNote',$this->oUserProfile->getUserNote());
		}
		LS::Make(ModuleViewer::class)->Assign('iCountFriendsUser',LS::Make(ModuleUser::class)->GetCountUsersFriend($this->oUserProfile->getId()));
		LS::Make(ModuleViewer::class)->Assign('sMenuSubItemSelect',$this->sMenuSubItemSelect);
		LS::Make(ModuleViewer::class)->Assign('sMenuHeadItemSelect',$this->sMenuHeadItemSelect);
		LS::Make(ModuleViewer::class)->Assign('USER_FRIEND_NULL',ModuleUser::USER_FRIEND_NULL);
		LS::Make(ModuleViewer::class)->Assign('USER_FRIEND_OFFER',ModuleUser::USER_FRIEND_OFFER);
		LS::Make(ModuleViewer::class)->Assign('USER_FRIEND_ACCEPT',ModuleUser::USER_FRIEND_ACCEPT);
		LS::Make(ModuleViewer::class)->Assign('USER_FRIEND_REJECT',ModuleUser::USER_FRIEND_REJECT);
		LS::Make(ModuleViewer::class)->Assign('USER_FRIEND_DELETE',ModuleUser::USER_FRIEND_DELETE);
		LS::Make(ModuleViewer::class)->Assign('sTopBlock', 'actions/ActionProfile/top_block.tpl');
	}
}