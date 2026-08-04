<?php
// Backward-compatible aliases — canonical classes are in src/Database/ and src/Schedule/ and src/Xlsx/
class_alias(BshsAms\Database\SchemaCache::class, 'SchemaCache');
class_alias(BshsAms\Schedule\ScheduleParser::class, 'ScheduleParser');
class_alias(BshsAms\Xlsx\SimpleXlsxParser::class, 'SimpleXlsxParser');
