<?php
/**
 * 唯一的網頁入口（Web Server 的 DocumentRoot 請指向 public/）
 */
require dirname(__FILE__) . '/../core/bootstrap.php';

App::run();
