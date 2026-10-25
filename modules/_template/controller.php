<?php
/**
 * __MODULE__ 模組
 * 新模組樣板（請用 scripts/shell/new_module.sh 產生，不要直接修改此資料夾）
 * __CLASS__NAME__ 與 __MODULE__ 會被腳本取代
 */
class __CLASS__NAME__ extends Controller
{
    public function indexAction()
    {
        $this->render('index', array(
            'message' => '模組 __MODULE__ 已上線',
        ));
    }
}
