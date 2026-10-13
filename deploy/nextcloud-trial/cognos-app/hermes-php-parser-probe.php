<?php
$row = ['source_snapshot' => '{}'];
$row['source_snapshot'] = json_decode((string)$row['source_snapshot'], true, 512, JSON_THROW_ON_ERROR);
var_dump($row);
