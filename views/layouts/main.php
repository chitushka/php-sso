<?php

use app\assets\AppAsset;
use yii\helpers\Html;

AppAsset::register($this);
?>
<?php $this->beginPage(); ?>
<!doctype html>
<html lang="<?= Yii::$app->language ?>">
<head>
    <meta charset="<?= Yii::$app->charset ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= Html::encode($this->title ?: 'SSO PHP') ?></title>
    <?php $this->head(); ?>
</head>
<body>
<?php $this->beginBody(); ?>
<?= $content ?>
<?php $this->endBody(); ?>
</body>
</html>
<?php $this->endPage(); ?>
