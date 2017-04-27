import * as Msg from './msg'
import $ from 'jquery'
import * as Tools from './tools'
import Emitter from './emitter'

export function ajax(url, params, callback, more) {
    more = more || {};
    params = params || {};
    params.security_ls_key = LIVESTREET_SECURITY_KEY;

    $.each(params, function (k, v) {
        if (typeof(v) == "boolean") {
            params[k] = v ? 1 : 0;
        }
    });

    if (url.indexOf('http://') != 0 && url.indexOf('https://') != 0 && url.indexOf('/') != 0) {
        url = aRouter['ajax'] + url + '/';
    }

    let ajaxOptions = {
        type: more.type || "POST",
        url: url,
        data: params,
        dataType: more.dataType || 'json',
        success: callback || function () {
            Tools.debug("ajax success: ");
            Tools.debug.apply(this, arguments);
        }.bind(this),
        error: more.error || function (msg) {
            Tools.debug("ajax error: ");
            Tools.debug.apply(this, arguments);
        }.bind(this),
        complete: more.complete || function (msg) {
            Tools.debug("ajax complete: ");
            Tools.debug.apply(this, arguments);
        }.bind(this),
	xhr: function()
	{
		var xhr = new window.XMLHttpRequest();
		// прогресс загрузки на сервер
		xhr.upload.addEventListener("progress", function(evt){
			if (evt.lengthComputable) {
				var percentComplete = evt.loaded / evt.total;
				// делать что-то...
				console.log("Complete upload: ",evt.loaded, evt.total);
			}
		}, false);
		// прогресс скачивания с сервера
		xhr.addEventListener("progress", function(evt){
			if (evt.lengthComputable) {
				var percentComplete = evt.loaded / evt.total;
				// делать что-то...
				console.log("Complete xhr: ",evt.loaded, evt.total);
			}
		}, false);
		return xhr;
	},
    };

    Emitter.emit('ls_ajax_before', [ajaxOptions], this);

    return $.ajax(ajaxOptions);
}

export async function asyncAjax(url, params, callback, more) {
    more = more || {};
    params = params || {};
    params.security_ls_key = LIVESTREET_SECURITY_KEY;

    $.each(params, function (k, v) {
        if (typeof(v) == "boolean") {
            params[k] = v ? 1 : 0;
        }
    });

    if (url.indexOf('http://') != 0 && url.indexOf('https://') != 0 && url.indexOf('/') != 0) {
        url = aRouter['ajax'] + url + '/';
    }

    let ajaxOptions = {
        type: more.type || "POST",
        url: url,
        data: params,
        dataType: more.dataType || 'json',
        success: callback || function () {
            Tools.debug("ajax success: ");
            Tools.debug.apply(this, arguments);
        }.bind(this),
        error: more.error || function (msg) {
            Tools.debug("ajax error: ");
            Tools.debug.apply(this, arguments);
        }.bind(this),
        complete: more.complete || function (msg) {
            Tools.debug("ajax complete: ");
            Tools.debug.apply(this, arguments);
        }.bind(this)
    };

    Emitter.emit('ls_ajax_before', [ajaxOptions], this);

    return $.ajax(ajaxOptions);
}

export function ajaxSubmit(url, form, callback, more) {
    more = more || {};
    if (typeof(form) == 'string') {
        form = $('#' + form);
    }
    if (url.indexOf('http://') != 0 && url.indexOf('https://') != 0 && url.indexOf('/') != 0) {
        url = aRouter['ajax'] + url + '/';
    }

    let options = {
        type: 'POST',
        url: url,
        data: {security_ls_key: LIVESTREET_SECURITY_KEY},
        success: function(data){
          if (typeof(data)=="string"){data = JSON.parse(data)}
          callback(data)
        } || function () {
            Tools.debug("ajax success: ");
            Tools.debug.apply(this, arguments);
        }.bind(this),
        error: more.error || function () {
            Tools.debug("ajax error: ");
            Tools.debug.apply(this, arguments);
        }.bind(this)

    };

    Emitter.emit('ls_ajaxsubmit_before', [options], this);
    console.log("Ajax load")

    form.ajaxSubmit(options)
}

/**
 * Загрузка изображения
 */
export async function ajaxUploadImg(form, sToLoad) {
    $("#"+form+"_submit").attr("disabled", true)
    $("#"+form+"_submit").toggleClass("hovered")
    let result = await ajaxSubmit('upload/image/', form, function (data) {
        if (data.bStateError) {
            Msg.error(data.sMsgTitle, data.sMsg);
        } else {
            if (!data.sText.length) {
                Msg.error("Ошибка", "Сервер не отдал картинку");
            }
            $.markItUp({replaceWith: data.sText});
            $('#window_upload_img').find('input[type="text"], input[type="file"]').val('');
            $('#window_upload_img').jqmHide();
            Emitter.emit('ajaxUploadImgAfter');
            $("#"+form+"_submit").toggleClass("hovered")
            $("#"+form+"_submit").attr("disabled", false)
        }
    }, {error: function() {
        Msg.error("Ошибка", "Изображение слишком тяжелое")
        $("#"+form+"_submit").toggleClass("hovered")
        $("#"+form+"_submit").attr("disabled", false)
    }});
    console.log(result)
}

export function LoadMoreActions(LastActionId){

	var params = {};
	params['LastActionId'] 	= LastActionId;

	$("#LoadMoreButton").toggleClass('loading');

	return this.ajax(aRouter['feedbacks']+'LoadMoreActions', params, function(data){
		if (data.aResult.Errors.length > 0){
			var $aErrors = data.aResult.Errors;
			for(var i=0; i < $aErrors.length; i++){
				var $sError	= $aErrors[i];
				ls.msg.error('',$sError);
			}
		} else {
			$("#stream-list").append(data.aResult.Text);
			$("#LoadMoreButton").remove();
		}
	});

};