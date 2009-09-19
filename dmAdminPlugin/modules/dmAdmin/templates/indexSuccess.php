<?php
use_stylesheet('core.browsers');
use_stylesheet('admin.log');
use_javascript('admin.log');

echo £('h1', dmConfig::get('site_name'));

echo £('div.admin_home.clearfix',

  £('div.dm_half',
    £('div.dm_box.log.user_log', array('json' => $userLogOptions),
      £('div.title',
        £link('dmUserLog/index')->textTitle(__('Expanded view'))->set('.s16block.s16_arrow_up_right').
        £('h2', __('User log'))
      ).
      £('div.dm_box_inner',
        $userLogView->renderEmpty()
      )
    )
  )
);