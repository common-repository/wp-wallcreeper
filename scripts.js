jQuery(document).ready(function () {
  isFresh();

  if (jQuery('input[name="wpwallcreeper[engine]"]:radio:checked').val() == 'memcached') {
    jQuery('.memcached-servers').show();
    jQuery('.memcached-auth').show();
  }
  else {
    jQuery('.memcached-servers').hide();
    jQuery('.memcached-auth').hide();
  }

  jQuery('input[name="wpwallcreeper[engine]"]').change(function () {
    if (jQuery('input[name="wpwallcreeper[engine]"]:radio:checked').val() == 'memcached') {
      jQuery('.memcached-servers').show();
      jQuery('.memcached-auth').show();
    }
    else {
      jQuery('.memcached-servers').hide();
      jQuery('.memcached-auth').hide();
    }
  });

  jQuery('.memcached-servers').on('click', '#add-memcached-server', function () {
    event.preventDefault();
    jQuery('.memcached-servers td fieldset label').append(jQuery('.memcached-servers td fieldset label div').last().clone());
    isFresh();
  });

  jQuery('.memcached-servers').on('click', '#remove-memcached-server', function () {
    event.preventDefault();
    jQuery(this).parent().remove();
    isFresh();
  });

  function isFresh() {
    jQuery('.memcached-servers td fieldset label div').each(function (index, value) {
      if (index === 0) {
        jQuery(this).find('span#remove-memcached-server').hide();
      } else {
        jQuery(this).find('span#remove-memcached-server').show();
      }

      jQuery(this).find('input').each(function () {
        jQuery(this).attr('name', jQuery(this).attr('name').replace(/[0-9]+/, index));
      });

    });
  }
});