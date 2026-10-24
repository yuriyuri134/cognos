<?php

declare(strict_types=1);

namespace OCA\Cognos\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\Util;

/**
 * 读法引擎试验页（主体空间）。数据由 experiments/reading-engine/publish.py 写入容器数据目录，
 * 内容是用户本人的会话原话，所以只对管理员开放（不加 NoAdminRequired）。
 */
final class ReadingsController extends Controller {
    private const DATA_FILE = '/var/www/html/data/cognos-readings/result.json';

    public function __construct(IRequest $request) {
        parent::__construct('cognos', $request);
    }

    #[NoCSRFRequired]
    public function page(): TemplateResponse {
        Util::addScript('cognos', 'readings');
        Util::addStyle('cognos', 'readings');
        return new TemplateResponse('cognos', 'readings', []);
    }

    public function data(): DataResponse {
        if (!is_readable(self::DATA_FILE)) {
            return new DataResponse(['message' => '还没有生成读法数据：先运行 experiments/reading-engine/publish.py'], 404);
        }
        $data = json_decode((string)file_get_contents(self::DATA_FILE), true);
        if (!is_array($data)) {
            return new DataResponse(['message' => '读法数据无法解析'], 500);
        }
        return new DataResponse($data);
    }
}
