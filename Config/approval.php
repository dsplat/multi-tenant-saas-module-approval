<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | 审批中心 配置
    |--------------------------------------------------------------------------
    | 通过 config('approval.xxx') 读取。基类 mergeModuleConfig() 会以
    | 模块名（approval）为键合并本文件。
    */

    'enabled' => true,

    // 路由前缀（留空则挂在 api/v1 下）
    'route_prefix' => '',

    // 列表默认每页条数
    'per_page' => 20,

    // 单步 SLA 上限（小时），超出发布校验直接 422
    'max_sla_hours' => 8760,

    // 审批意见最大长度（字符）
    'comment_max_length' => 2000,

    // 同步导出单次最大行数（超出标记 truncated，异步导出不在 P0 范围）
    'export_max_rows' => 1000,
];
