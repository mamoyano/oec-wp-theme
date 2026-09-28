/* OEC Theme — admin-settings.js */
(function ($) {
  'use strict';

  var config = window.oecAdmin || {};

  /* ============================================================
     COLOR PICKERS
     ============================================================ */
  // Mismo cálculo que oec_lighten_hex() en PHP: mezcla hacia blanco.
  function lighten(hex, percent) {
    hex = String(hex || '').replace('#', '');
    if (hex.length === 3) hex = hex.replace(/(.)/g, '$1$1');
    if (!/^[0-9a-f]{6}$/i.test(hex)) return null;
    var f = percent / 100, out = '#';
    for (var i = 0; i < 6; i += 2) {
      var c = parseInt(hex.substr(i, 2), 16);
      out += ('0' + Math.round(c + (255 - c) * f).toString(16)).slice(-2);
    }
    return out;
  }

  function updateDerived(key, color) {
    $('.oec-derived__dot[data-from="' + key + '"]').each(function () {
      var c = lighten(color, +$(this).data('mix'));
      if (c) $(this).css('background', c);
    });
  }

  $('.oec-color-picker').wpColorPicker({
    change: function (event, ui) {
      updateDerived($(this).data('key'), ui.color.toString());
    },
    clear: function () {
      var $input = $(this).closest('.wp-picker-container').find('.oec-color-picker');
      // After clear, WP sets the input back to default; wait a tick
      setTimeout(function () {
        updateDerived($input.data('key'), $input.data('default-color'));
      }, 10);
    },
  });

  /* ============================================================
     RESET COLORS
     ============================================================ */
  $('#oec-reset-colors').on('click', function () {
    $('.oec-color-picker').each(function () {
      var $input = $(this);
      var def    = $input.data('default-color');
      // Update the WP color picker widget
      $input.wpColorPicker('color', def);
      updateDerived($input.data('key'), def);
    });
  });

  /* ============================================================
     FAVICON MEDIA UPLOADER
     ============================================================ */
  var faviconFrame;

  $('#oec-upload-favicon').on('click', function (e) {
    e.preventDefault();

    if (!faviconFrame) {
      faviconFrame = wp.media({
        title:    config.faviconTitle  || 'Seleccionar favicon',
        button:   { text: config.faviconButton || 'Usar como favicon' },
        library:  { type: 'image' },
        multiple: false,
      });

      faviconFrame.on('select', function () {
        var attachment = faviconFrame.state().get('selection').first().toJSON();
        var url = (attachment.sizes && attachment.sizes.thumbnail) ? attachment.sizes.thumbnail.url : attachment.url;

        $('#oec-favicon-id').val(attachment.id);
        $('#oec-favicon-preview').empty().append($('<img alt="">').attr('src', url));
        $('#oec-upload-favicon').text(config.changeFavicon || 'Cambiar favicon');
        $('#oec-remove-favicon').show();
      });
    }

    faviconFrame.open();
  });

  $('#oec-remove-favicon').on('click', function (e) {
    e.preventDefault();
    $('#oec-favicon-id').val('0');
    $('#oec-favicon-preview').html(
      '<span class="oec-favicon-placeholder">' + (config.noFavicon || 'Sin favicon') + '</span>'
    );
    $('#oec-upload-favicon').text(config.uploadFavicon || 'Subir favicon');
    $(this).hide();
  });

  /* ============================================================
     LOGO MEDIA UPLOADER
     ============================================================ */
  var mediaFrame;

  $('#oec-upload-logo').on('click', function (e) {
    e.preventDefault();

    if (mediaFrame) {
      mediaFrame.open();
      return;
    }

    mediaFrame = wp.media({
      title:    config.mediaTitle  || 'Seleccionar logo',
      button:   { text: config.mediaButton || 'Usar como logo' },
      library:  { type: 'image' },
      multiple: false,
    });

    mediaFrame.on('select', function () {
      var attachment = mediaFrame.state().get('selection').first().toJSON();
      var url        = attachment.url;

      $('#oec-logo-id').val(attachment.id);
      $('#oec-logo-url').val(url);

      var $preview = $('#oec-logo-preview');
      $preview.html(
        '<img src="' + url + '" alt="Logo" class="oec-logo-img" ' +
        'style="max-height:58px;max-width:220px;width:auto;display:block;">'
      );

      $('#oec-upload-logo').text(config.changeLabel || 'Cambiar logo');
      $('#oec-remove-logo').show();
    });

    mediaFrame.open();
  });

  /* Remove logo */
  $('#oec-remove-logo').on('click', function (e) {
    e.preventDefault();
    $('#oec-logo-id').val('');
    $('#oec-logo-url').val('');
    $('#oec-logo-preview').html(
      '<span class="oec-logo-placeholder">' + (config.noLogo || 'Sin logo cargado') + '</span>'
    );
    $('#oec-upload-logo').text(config.uploadLabel || 'Subir logo');
    $(this).hide();
  });

  /* ============================================================
     TRACKER BADGES (live update on input)
     ============================================================ */
  $('.oec-tracker-input').on('input', function () {
    var $input  = $(this);
    var tracker = $input.data('tracker');
    var val     = $.trim($input.val());
    var $badge  = $('#status-' + tracker);

    if (val) {
      $badge.html('<span class="oec-badge oec-badge--on">Activo</span>');
    } else {
      $badge.html('<span class="oec-badge oec-badge--off">Inactivo</span>');
    }
  });

  /* ============================================================
     AI CATALOG SYNC
     ============================================================ */
  $(document).on('click', '#oec-ai-sync-now', function () {
    var $btn  = $(this);
    var $msg  = $('#oec-ai-sync-msg');
    var nonce = $btn.data('nonce');

    $btn.prop('disabled', true).text('Sincronizando…');
    $msg.text('');

    // Cada llamada corre una tanda del sync (ver OEC_AI_Catalog::ajax_sync);
    // se repite mientras siga en curso, mostrando el avance.
    var tick = function (start) {
      $.post(ajaxurl, { action: 'oec_ai_sync_catalog', nonce: nonce, start: start ? 1 : 0 }, function (res) {
        var data = (res && res.data) || {};
        if (!res.success) {
          $msg.css('color', '#c00').text('Error: ' + (res.data || 'Falló la sincronización.'));
          done();
        } else if (data.status === 'running') {
          $msg.css('color', '#646970').text(data.progress || 'Sincronizando…');
          setTimeout(function () { tick(false); }, 1500);
        } else if (data.status === 'ok') {
          var closed = data.closed_count ? ' y ' + data.closed_count + ' cerradas' : '';
          $msg.css('color', '#1e7e34').text('✓ Sincronizado — ' + (data.count || 0) + ' formaciones abiertas' + closed + '.');
          done();
        } else {
          $msg.css('color', '#c00').text('Error: ' + ((data.errors || []).slice(-1)[0] || 'Falló la sincronización.'));
          done();
        }
      }).fail(function () {
        // Un corte de una tanda no frena el trabajo: se reintenta.
        setTimeout(function () { tick(false); }, 5000);
      });
    };
    var done = function () { $btn.prop('disabled', false).text('🔄 Sincronizar ahora'); };
    tick(true);
  });

})(jQuery);
