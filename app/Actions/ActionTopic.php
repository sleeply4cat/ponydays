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

use App\Entities\EntityTopic;
use App\Entities\EntityUser;
use App\Modules\ModuleACL;
use App\Modules\ModuleBlog;
use App\Modules\ModuleCast;
use App\Modules\ModuleComment;
use App\Modules\ModuleStream;
use App\Modules\ModuleTopic;
use App\Modules\ModuleUser;
use Engine\Action;
use Engine\Config;
use Engine\LS;
use Engine\Modules\ModuleHook;
use Engine\Modules\ModuleLang;
use Engine\Modules\ModuleLogger;
use Engine\Modules\ModuleMessage;
use Engine\Modules\ModuleSecurity;
use Engine\Modules\ModuleText;
use Engine\Modules\ModuleViewer;
use Engine\Router;

/**
 * Экшен обработки УРЛа вида /topic/ - управление своими топиками
 *
 * @package actions
 * @since   1.0
 */
class ActionTopic extends Action
{
    /**
     * Главное меню
     *
     * @var string
     */
    protected $sMenuHeadItemSelect = 'blog';
    /**
     * Меню
     *
     * @var string
     */
    protected $sMenuItemSelect = 'topic';
    /**
     * СубМеню
     *
     * @var string
     */
    protected $sMenuSubItemSelect = 'topic';
    /**
     * Текущий юзер
     *
     * @var EntityUser|null
     */
    protected $oUserCurrent = null;

    /**
     * Инициализация
     *
     */
    public function Init()
    {
        /**
         * Проверяем авторизован ли юзер
         */
        if (!LS::Make(ModuleUser::class)->IsAuthorization()) {
            parent::EventNotFound();

            return;
        }
        $this->oUserCurrent = LS::Make(ModuleUser::class)->GetUserCurrent();
        /**
         * Усанавливаем дефолтный евент
         */
        $this->SetDefaultEvent('add');
        /**
         * Устанавливаем title страницы
         */
        LS::Make(ModuleViewer::class)->AddHtmlTitle(LS::Make(ModuleLang::class)->Get('topic_title'));
    }

    /**
     * Регистрируем евенты
     *
     */
    protected function RegisterEvent()
    {
        $this->AddEvent('add', 'EventAdd');
        $this->AddEventPreg('/^published$/i', '/^(page([1-9]\d{0,5}))?$/i', 'EventShowTopics');
        $this->AddEventPreg('/^saved$/i', '/^(page([1-9]\d{0,5}))?$/i', 'EventShowTopics');
        $this->AddEvent('edit', 'EventEdit');
        $this->AddEvent('delete', 'EventRemove');
        $this->AddEvent('restore', 'EventRestore');
    }


    /**********************************************************************************
     ************************ РЕАЛИЗАЦИЯ ЭКШЕНА ***************************************
     **********************************************************************************
     */

    /**
     * Редактирование топика
     *
     */
    protected function EventEdit()
    {
        /**
         * Получаем номер топика из УРЛ и проверяем существует ли он
         */
        $sTopicId = $this->GetParam(0);
        if (!($oTopic = LS::Make(ModuleTopic::class)->GetTopicById($sTopicId))) {
            parent::EventNotFound();

            return;
        }
        if ($oTopic->getId() == 200 and LS::Make(ModuleUser::class)->GetUserCurrent()->getId() != 1) {
            parent::EventNotFound();

            return;
        }

        /**
         * Проверяем тип топика
         */
        if ($oTopic->getType() != 'topic') {
            parent::EventNotFound();

            return;
        }
        /**
         * Если права на редактирование
         */
        if (!LS::Make(ModuleACL::class)->IsAllowEditTopic($oTopic, $this->oUserCurrent)) {
            parent::EventNotFound();

            return;
        }
        /**
         * Вызов хуков
         */
        LS::Make(ModuleHook::class)->Run('topic_edit_show', ['oTopic' => $oTopic]);
        /**
         * Загружаем переменные в шаблон
         */
        LS::Make(ModuleViewer::class)->Assign(
            'aBlogsAllow',
            LS::Make(ModuleBlog::class)->GetBlogsAllowByUser($this->oUserCurrent)
        );
        LS::Make(ModuleViewer::class)->AddHtmlTitle(LS::Make(ModuleLang::class)->Get('topic_topic_edit'));
        /**
         * Устанавливаем шаблон вывода
         */
        $this->SetTemplateAction('add');
        /**
         * Проверяем отправлена ли форма с данными(хотяб одна кнопка)
         */
        if (isset($_REQUEST['submit_topic_publish']) or isset($_REQUEST['submit_topic_save'])) {
            /**
             * Обрабатываем отправку формы
             */
            $this->SubmitEdit($oTopic);

            return;
        } else {
            /**
             * Заполняем поля формы для редактирования
             * Только перед отправкой формы!
             */
            $_REQUEST['topic_title'] = $oTopic->getTitle();
            $_REQUEST['topic_text'] = $oTopic->getTextSource();
            $_REQUEST['topic_tags'] = $oTopic->getTags();
            $_REQUEST['blog_id'] = $oTopic->getBlogId();
            $_REQUEST['topic_id'] = $oTopic->getId();
            $_REQUEST['topic_publish_index'] = $oTopic->getPublishIndex();
            $_REQUEST['topic_forbid_comment'] = $oTopic->getForbidComment();
        }
    }

    /**
     * Удаление топика
     *
     */
    protected function EventDelete()
    {
        LS::Make(ModuleSecurity::class)->ValidateSendForm();
        /**
         * Получаем номер топика из УРЛ и проверяем существует ли он
         */
        $sTopicId = $this->GetParam(0);
        if (!($oTopic = LS::Make(ModuleTopic::class)->GetTopicById($sTopicId))) {
            parent::EventNotFound();

            return;
        }
        /**
         * проверяем есть ли право на удаление топика
         */
        if (!LS::Make(ModuleACL::class)->IsAllowDeleteTopic($oTopic, $this->oUserCurrent)) {
            parent::EventNotFound();

            return;
        }
        /**
         * Удаляем топик
         */
        LS::Make(ModuleHook::class)->Run('topic_delete_before', ['oTopic' => $oTopic]);
        LS::Make(ModuleTopic::class)->DeleteTopic($oTopic);
        LS::Make(ModuleHook::class)->Run('topic_delete_after', ['oTopic' => $oTopic]);
        /**
         * Перенаправляем на страницу со списком топиков из блога этого топика
         */
        Router::Location($oTopic->getBlog()->getUrlFull());
    }

    /**
     * Удаление топика в корзину
     *
     */
    protected function EventRemove()
    {

        LS::Make(ModuleSecurity::class)->ValidateSendForm();
        /**
         * Получаем номер топика из УРЛ и проверяем существует ли он
         */
        $sTopicId = $this->GetParam(0);
        if (!($oTopic = LS::Make(ModuleTopic::class)->GetTopicById($sTopicId))) {
            parent::EventNotFound();

            return;
        }
        /**
         * проверяем есть ли право на удаление топика
         */
        if (!LS::Make(ModuleACL::class)->IsAllowDeleteTopic($oTopic, $this->oUserCurrent)) {
            parent::EventNotFound();

            return;
        }
        /**
         * Удаляем топик
         */
        LS::Make(ModuleHook::class)->Run('topic_delete_before', ['oTopic' => $oTopic]);
        $oTopic->setDeleted(true);
        if (LS::Make(ModuleTopic::class)->UpdateTopic($oTopic)) {
        } else {
            parent::EventNotFound();

            return;
        }
        LS::Make(ModuleHook::class)->Run('topic_delete_after', ['oTopic' => $oTopic]);
        /**
         * Перенаправляем на страницу со списком топиков из блога этого топика
         */
        $sLogText = $this->oUserCurrent->getLogin()." удалил топик ".$oTopic->getId();
        LS::Make(ModuleLogger::class)->Notice($sLogText);
        Router::Location($oTopic->getBlog()->getUrlFull());
    }

    /**
     * Восстановление топика из корзины
     *
     */
    protected function EventRestore()
    {

        LS::Make(ModuleSecurity::class)->ValidateSendForm();
        /**
         * Получаем номер топика из УРЛ и проверяем существует ли он
         */
        $sTopicId = $this->GetParam(0);
        if (!($oTopic = LS::Make(ModuleTopic::class)->GetDeletedTopicById($sTopicId))) {
            parent::EventNotFound();

            return;
        }
        /**
         * проверяем есть ли право на удаление топика
         */
        if (!LS::Make(ModuleACL::class)->IsAllowDeleteTopic($oTopic, $this->oUserCurrent)) {
            parent::EventNotFound();

            return;
        }
        /**
         * Восстанавливаем топик
         */
        $oTopic->setDeleted(false);
        if (LS::Make(ModuleTopic::class)->UpdateTopic($oTopic)) {
        } else {
            parent::EventNotFound();

            return;
        }
        /**
         * Перенаправляем на страницу со списком топиков из блога этого топика
         */
        $sLogText = $this->oUserCurrent->getLogin()." восстановил топик ".$oTopic->getId();
        LS::Make(ModuleLogger::class)->Notice($sLogText);
        Router::Location($oTopic->getBlog()->getUrlFull().'deleted/');
    }

    /**
     * Добавление топика
     *
     */
    protected function EventAdd()
    {
        /**
         * Вызов хуков
         */
        LS::Make(ModuleHook::class)->Run('topic_add_show');
        /**
         * Загружаем переменные в шаблон
         */
        $aBlogs = LS::Make(ModuleBlog::class)->GetBlogsAllowByUser($this->oUserCurrent);
        $myCmp = function ($a, $b) {
            if (strcasecmp($a->getTitle(), $b->getTitle()) == 0) {
                return 0;
            }

            return strcasecmp($a->getTitle(), $b->getTitle()) > 0 ? 1 : -1;
        };
        usort($aBlogs, $myCmp);
        LS::Make(ModuleViewer::class)->Assign('aBlogsAllow', $aBlogs);
        LS::Make(ModuleViewer::class)->AddHtmlTitle(LS::Make(ModuleLang::class)->Get('topic_topic_create'));
        /**
         * Обрабатываем отправку формы
         */
        $this->SubmitAdd();
    }

    /**
     * Выводит список топиков
     *
     */
    protected function EventShowTopics()
    {
        /**
         * Меню
         */
        $this->sMenuSubItemSelect = $this->sCurrentEvent;
        /**
         * Передан ли номер страницы
         */
        $iPage = $this->GetParamEventMatch(0, 2) ? $this->GetParamEventMatch(0, 2) : 1;
        /**
         * Получаем список топиков
         */
        $aResult = LS::Make(ModuleTopic::class)->GetTopicsPersonalByUser(
            $this->oUserCurrent->getId(),
            $this->sCurrentEvent == 'published' ? 1 : 0,
            $iPage,
            Config::Get('module.topic.per_page')
        );
        $aTopics = $aResult['collection'];
        /**
         * Формируем постраничность
         */
        $aPaging = LS::Make(ModuleViewer::class)->MakePaging(
            $aResult['count'],
            $iPage,
            Config::Get('module.topic.per_page'),
            Config::Get('pagination.pages.count'),
            Router::GetPath('topic').$this->sCurrentEvent
        );
        /**
         * Загружаем переменные в шаблон
         */
        LS::Make(ModuleViewer::class)->Assign('aPaging', $aPaging);
        LS::Make(ModuleViewer::class)->Assign('aTopics', $aTopics);
        LS::Make(ModuleViewer::class)->AddHtmlTitle(
            LS::Make(ModuleLang::class)->Get('topic_menu_'.$this->sCurrentEvent)
        );
    }

    /**
     * Обработка добавления топика
     */
    protected function SubmitAdd()
    {
        /**
         * Проверяем отправлена ли форма с данными(хотяб одна кнопка)
         */
        if (!isPost('submit_topic_publish') and !isPost('submit_topic_save')) {
            return;
        }
        $oTopic = new EntityTopic();
        $oTopic->_setValidateScenario('topic');
        /**
         * Заполняем поля для валидации
         */
        $oTopic->setBlogId(getRequestStr('blog_id'));
        $oTopic->setTitle(strip_tags(getRequestStr('topic_title')));
        $oTopic->setTextSource(getRequestStr('topic_text'));
        $oTopic->setTags(getRequestStr('topic_tags'));
        $oTopic->setUserId($this->oUserCurrent->getId());
        $oTopic->setType('topic');
        $oTopic->setDateAdd(date("Y-m-d H:i:s"));
        $oTopic->setUserIp(func_getIp());
        /**
         * Проверка корректности полей формы
         */
        if (!$this->checkTopicFields($oTopic)) {
            return;
        }
        /**
         * Определяем в какой блог делаем запись
         */
        $iBlogId = $oTopic->getBlogId();
        if ($iBlogId == 0) {
            $oBlog = LS::Make(ModuleBlog::class)->GetPersonalBlogByUserId($this->oUserCurrent->getId());
        } else {
            $oBlog = LS::Make(ModuleBlog::class)->GetBlogById($iBlogId);
        }
        /**
         * Если блог не определен выдаем предупреждение
         */
        if (!$oBlog) {
            LS::Make(ModuleMessage::class)->AddErrorSingle(
                LS::Make(ModuleLang::class)->Get('topic_create_blog_error_unknown'),
                LS::Make(ModuleLang::class)->Get('error')
            );

            return;
        }
        /**
         * Проверяем права на постинг в блог
         */
        if (!LS::Make(ModuleACL::class)->IsAllowBlog($oBlog, $this->oUserCurrent)) {
            LS::Make(ModuleMessage::class)->AddErrorSingle(
                LS::Make(ModuleLang::class)->Get('topic_create_blog_error_noallow'),
                LS::Make(ModuleLang::class)->Get('error')
            );

            return;
        }
        if ($oBlogUser =
            LS::Make(ModuleBlog::class)->GetBlogUserByBlogIdAndUserId($oBlog->getId(), $this->oUserCurrent->getId())
        ) {
            if ($oBlogUser->getUserRole() == ModuleBlog::BLOG_USER_ROLE_RO) {
                LS::Make(ModuleMessage::class)->AddErrorSingle(
                    LS::Make(ModuleLang::class)->Get('topic_create_blog_error_noallow'),
                    LS::Make(ModuleLang::class)->Get('error')
                );

                return;
            }
        }
        /**
         * Проверяем разрешено ли постить топик по времени
         */
        if (isPost('submit_topic_publish') and !LS::Make(ModuleACL::class)->CanPostTopicTime($this->oUserCurrent)) {
            LS::Make(ModuleMessage::class)->AddErrorSingle(
                LS::Make(ModuleLang::class)->Get('topic_time_limit'),
                LS::Make(ModuleLang::class)->Get('error')
            );

            return;
        }
        /**
         * Теперь можно смело добавлять топик к блогу
         */
        $oTopic->setBlogId($oBlog->getId());
        /**
         * Получаемый и устанавливаем разрезанный текст по тегу <cut>
         */
        list($sTextShort, $sTextNew, $sTextCut) = LS::Make(ModuleText::class)->Cut($oTopic->getTextSource());

        $oTopic->setCutText($sTextCut);
        $oTopic->setText(LS::Make(ModuleText::class)->Parser($sTextNew));
        $oTopic->setTextShort(LS::Make(ModuleText::class)->Parser($sTextShort));
        /**
         * Публикуем или сохраняем
         */
        if (isset($_REQUEST['submit_topic_publish'])) {
            $oTopic->setPublish(1);
            $oTopic->setPublishDraft(1);
        } else {
            $oTopic->setPublish(0);
            $oTopic->setPublishDraft(0);
        }
        /**
         * Принудительный вывод на главную
         */
        $oTopic->setPublishIndex(0);
        if (LS::Make(ModuleACL::class)->IsAllowPublishIndex($this->oUserCurrent)) {
            if (getRequest('topic_publish_index')) {
                $oTopic->setPublishIndex(1);
            }
        }
        /**
         * Запрет на комментарии к топику
         */
        $oTopic->setForbidComment(0);
        if (getRequest('topic_forbid_comment')) {
            $oTopic->setForbidComment(1);
        }
        /**
         * Запускаем выполнение хуков
         */
        LS::Make(ModuleHook::class)->Run('topic_add_before', ['oTopic' => $oTopic, 'oBlog' => $oBlog]);
        /**
         * Добавляем топик
         */
        if (LS::Make(ModuleTopic::class)->AddTopic($oTopic)) {
            LS::Make(ModuleHook::class)->Run('topic_add_after', ['oTopic' => $oTopic, 'oBlog' => $oBlog]);
            if ($oTopic->getPublish() == 1) {
                $oTopic->setBlog(LS::Make(ModuleBlog::class)->GetBlogById($oTopic->getBlogId()));
                LS::Make(ModuleCast::class)->sendCastNotify('topic', $oTopic, null, $oTopic->getTextSource());
            }
            /**
             * Получаем топик, чтоб подцепить связанные данные
             */
            $oTopic = LS::Make(ModuleTopic::class)->GetTopicById($oTopic->getId());
            /**
             * Обновляем количество топиков в блоге
             */
            LS::Make(ModuleBlog::class)->RecalculateCountTopicByBlogId($oTopic->getBlogId());
            /**
             * Добавляем автора топика в подписчики на новые комментарии к этому топику
             */
            /**
             * Делаем рассылку спама всем, кто состоит в этом блоге
             */
            if ($oTopic->getPublish() == 1 and $oBlog->getType() != 'personal') {
                LS::Make(ModuleTopic::class)->SendNotifyTopicNew($oBlog, $oTopic, $this->oUserCurrent);
            }
            /**
             * Добавляем событие в ленту
             */
            LS::Make(ModuleStream::class)->write(
                $oTopic->getUserId(),
                'add_topic',
                $oTopic->getId(),
                $oTopic->getPublish() && !in_array($oBlog->getType(), ['close', 'invite'])
            );
            Router::Location($oTopic->getUrl());
        } else {
            LS::Make(ModuleMessage::class)->AddErrorSingle(LS::Make(ModuleLang::class)->Get('system_error'));
            Router::Action('error');
        }
    }

    /**
     * Обработка редактирования топика
     *
     * @param \App\Entities\EntityTopic $oTopic
     */
    protected function SubmitEdit($oTopic)
    {
        $oTopic->_setValidateScenario('topic');
        /**
         * Сохраняем старое значение идентификатора блога
         */
        $sBlogIdOld = $oTopic->getBlogId();
        $isAllowControlTopic = LS::Make(ModuleACL::class)->IsAllowControlTopic($oTopic, $this->oUserCurrent);
        /**
         * Заполняем поля для валидации
         */
        $oTopic->setBlogId(getRequestStr('blog_id'));
        $oTopic->setTitle(strip_tags(getRequestStr('topic_title')));
        $oTopic->setTextSource(getRequestStr('topic_text'));
        $oTopic->setTags(getRequestStr('topic_tags'));
        $oTopic->setUserIp(func_getIp());
        /**
         * Проверка корректности полей формы
         */
        if (!$this->checkTopicFields($oTopic)) {
            return;
        }
        /**
         * Определяем в какой блог делаем запись
         */
        $iBlogId = $oTopic->getBlogId();
        if ($iBlogId != $sBlogIdOld) {
            if (!$isAllowControlTopic) {
                LS::Make(ModuleMessage::class)->AddErrorSingle(
                    LS::Make(ModuleLang::class)->Get('not_access'),
                    LS::Make(ModuleLang::class)->Get('not_access')
                );
                Router::Action('error');

                return;
            }
            if ($oTopic->isControlLocked()) {
                $oTopic->setLockControl(false);
                if (!LS::Make(ModuleTopic::class)->UpdateControlLock($oTopic)) {
                    LS::Make(ModuleMessage::class)->AddErrorSingle(
                        LS::Make(ModuleLang::class)->Get('system_error'),
                        LS::Make(ModuleLang::class)->Get('error')
                    );

                    return;
                }
            }
        }
        if ($iBlogId == 0) {
            $oBlog = LS::Make(ModuleBlog::class)->GetPersonalBlogByUserId($oTopic->getUserId());
        } else {
            $oBlog = LS::Make(ModuleBlog::class)->GetBlogById($iBlogId);
        }
        /**
         * Если блог не определен выдаем предупреждение
         */
        if (!$oBlog) {
            LS::Make(ModuleMessage::class)->AddErrorSingle(
                LS::Make(ModuleLang::class)->Get('topic_create_blog_error_unknown'),
                LS::Make(ModuleLang::class)->Get('error')
            );

            return;
        }
        /**
         * Проверяем права на постинг в блог
         */
        if (!LS::Make(ModuleACL::class)->IsAllowBlog($oBlog, $this->oUserCurrent)) {
            LS::Make(ModuleMessage::class)->AddErrorSingle(
                LS::Make(ModuleLang::class)->Get('topic_create_blog_error_noallow'),
                LS::Make(ModuleLang::class)->Get('error')
            );

            return;
        }
        /**
         * Проверяем разрешено ли постить топик по времени
         */
        if (isPost('submit_topic_publish') and !$oTopic->getPublishDraft() and !LS::Make(ModuleACL::class)
                ->CanPostTopicTime($this->oUserCurrent)
        ) {
            LS::Make(ModuleMessage::class)->AddErrorSingle(
                LS::Make(ModuleLang::class)->Get('topic_time_limit'),
                LS::Make(ModuleLang::class)->Get('error')
            );

            return;
        }
        $oTopic->setBlogId($oBlog->getId());
        /**
         * Получаемый и устанавливаем разрезанный текст по тегу <cut>
         */
        list($sTextShort, $sTextNew, $sTextCut) = LS::Make(ModuleText::class)->Cut($oTopic->getTextSource());

        $oTopic->setCutText($sTextCut);
        $oTopic->setText(LS::Make(ModuleText::class)->Parser($sTextNew));
        $oTopic->setTextShort(LS::Make(ModuleText::class)->Parser($sTextShort));
        /**
         * Публикуем или сохраняем в черновиках
         */
        $bSendNotify = false;
        if (isset($_REQUEST['submit_topic_publish'])) {
            $oTopic->setPublish(1);
            if ($oTopic->getPublishDraft() == 0) {
                $oTopic->setPublishDraft(1);
                $oTopic->setDateAdd(date("Y-m-d H:i:s"));
                $bSendNotify = true;
            }
        } else {
            // ! [текущая реализация] Если пост находится в черновиках — его можно опубликовать даже в случае установки topic_lock_control.
            if (!$isAllowControlTopic) {
                LS::Make(ModuleMessage::class)->AddErrorSingle(
                    LS::Make(ModuleLang::class)->Get('not_access'),
                    LS::Make(ModuleLang::class)->Get('not_access')
                );
                Router::Action('error');

                return;
            }
            $oTopic->setPublish(0);
        }
        /**
         * Принудительный вывод на главную
         */
        if (LS::Make(ModuleACL::class)->IsAllowPublishIndex($this->oUserCurrent)) {
            if (getRequest('topic_publish_index')) {
                $oTopic->setPublishIndex(1);
            } else {
                $oTopic->setPublishIndex(0);
            }
        }
        /**
         * Запрет на комментарии к топику
         */
        $oTopic->setForbidComment(0);
        if (getRequest('topic_forbid_comment')) {
            // ! [текущая реализация] Если комменты закрыты — их можно открыть даже в случае установки topic_lock_control.
            if (!$isAllowControlTopic) {
                LS::Make(ModuleMessage::class)->AddErrorSingle(
                    LS::Make(ModuleLang::class)->Get('not_access'),
                    LS::Make(ModuleLang::class)->Get('not_access')
                );
                Router::Action('error');

                return;
            }
            $oTopic->setForbidComment(1);
        }
        LS::Make(ModuleHook::class)->Run('topic_edit_before', ['oTopic' => $oTopic, 'oBlog' => $oBlog]);
        /**
         * Сохраняем топик
         */
        if (LS::Make(ModuleTopic::class)->UpdateTopic($oTopic)) {
            LS::Make(ModuleHook::class)->Run(
                'topic_edit_after',
                ['oTopic' => $oTopic, 'oBlog' => $oBlog, 'bSendNotify' => &$bSendNotify]
            );
            if ($oTopic->getPublish() == 1) {
                $oTopic->setBlog(LS::Make(ModuleBlog::class)->GetBlogById($oTopic->getBlogId()));
                LS::Make(ModuleCast::class)->sendCastNotify('topic', $oTopic, null, $oTopic->getTextSource());
            }
            /**
             * Обновляем данные в комментариях, если топик был перенесен в новый блог
             */
            if ($sBlogIdOld != $oTopic->getBlogId()) {
                LS::Make(ModuleComment::class)->UpdateTargetParentByTargetId(
                    $oTopic->getBlogId(),
                    'topic',
                    $oTopic->getId()
                );
                LS::Make(ModuleComment::class)->UpdateTargetParentByTargetIdOnline(
                    $oTopic->getBlogId(),
                    'topic',
                    $oTopic->getId()
                );
            }
            /**
             * Обновляем количество топиков в блоге
             */
            if ($sBlogIdOld != $oTopic->getBlogId()) {
                LS::Make(ModuleBlog::class)->RecalculateCountTopicByBlogId($sBlogIdOld);
            }
            LS::Make(ModuleBlog::class)->RecalculateCountTopicByBlogId($oTopic->getBlogId());
            /**
             * Добавляем событие в ленту
             */
            LS::Make(ModuleStream::class)->write(
                $oTopic->getUserId(),
                'add_topic',
                $oTopic->getId(),
                $oTopic->getPublish() && $oBlog->getType() != 'close'
            );
            /**
             * Рассылаем о новом топике подписчикам блога
             */
            if ($bSendNotify) {
                LS::Make(ModuleTopic::class)->SendNotifyTopicNew($oBlog, $oTopic, $oTopic->getUser());
            }
            if (!$oTopic->getPublish() and !$this->oUserCurrent->isAdministrator() and $this->oUserCurrent->getId()
                != $oTopic->getUserId()
            ) {
                Router::Location($oBlog->getUrlFull());
            }
            Router::Location($oTopic->getUrl());
        } else {
            LS::Make(ModuleMessage::class)->AddErrorSingle(LS::Make(ModuleLang::class)->Get('system_error'));
            Router::Action('error');

            return;
        }
    }

    /**
     * Проверка полей формы
     *
     * @return bool
     */
    protected function checkTopicFields($oTopic)
    {
        LS::Make(ModuleSecurity::class)->ValidateSendForm();

        $bOk = true;
        /**
         * Валидируем топик
         */
        if (!$oTopic->_Validate()) {
            LS::Make(ModuleMessage::class)->AddError(
                $oTopic->_getValidateError(),
                LS::Make(ModuleLang::class)->Get('error')
            );
            $bOk = false;
        }
        /**
         * Выполнение хуков
         */
        LS::Make(ModuleHook::class)->Run('check_topic_fields', ['bOk' => &$bOk]);

        return $bOk;
    }

    /**
     * При завершении экшена загружаем необходимые переменные
     *
     */
    public function EventShutdown()
    {
        LS::Make(ModuleViewer::class)->Assign('sMenuHeadItemSelect', $this->sMenuHeadItemSelect);
        LS::Make(ModuleViewer::class)->Assign('sMenuItemSelect', $this->sMenuItemSelect);
        LS::Make(ModuleViewer::class)->Assign('sMenuSubItemSelect', $this->sMenuSubItemSelect);
    }
}
