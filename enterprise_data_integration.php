<?php

declare(strict_types=1);

// PSR-3 호환 로거 인터페이스 (실제로는 Monolog 등 사용)
interface LoggerInterface
{
    public function info(string $message, array $context = []): void;
    public function warning(string $message, array $context = []): void;
    public function error(string $message, array $context = []): void;
    public function critical(string $message, array $context = []): void;
}

// 간단한 콘솔 로거 구현체
class ConsoleLogger implements LoggerInterface
{
    public function info(string $message, array $context = []): void
    {
        $this->log('INFO', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('WARNING', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('ERROR', $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->log('CRITICAL', $message, $context);
    }

    private function log(string $level, string $message, array $context): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' ' . json_encode($context) : '';
        echo "[$timestamp] [$level] $message$contextStr\n";
    }
}

// --- 설정 (실제로는 .env, config 파일 등에서 로드) ---
class AppConfig
{
    public const API_BASE_URL = 'https://jsonplaceholder.typicode.com';
    public const REQUEST_TIMEOUT_SEC = 10;
    public const MAX_RETRIES = 3;
    public const RETRY_BACKOFF_FACTOR_SEC = 0.5; // Exponential backoff: 0.5s, 1s, 2s...
    public const CONCURRENT_API_LIMIT = 5; // 동시 API 요청 수 제한
}

// --- 데이터 모델 (PHP 8.1+ Enum 또는 DTO 클래스로 더 강화 가능) ---
// 실제로는 더 복잡한 Validation 로직이 DTO 내부에 포함될 수 있음 (예: Symfony Validator)
class UserDTO
{
    public int $id;
    public string $name;
    public string $email;
    public string $username;

    public function __construct(array $data)
    {
        // 간단한 유효성 검사 (실제는 더 강력한 라이브러리 사용)
        if (!isset($data['id'], $data['name'], $data['email'], $data['username'])) {
            throw new InvalidArgumentException("User data is incomplete.");
        }
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("Invalid email format for user ID {$data['id']}.");
        }

        $this->id = (int) $data['id'];
        $this->name = (string) $data['name'];
        $this->email = (string) $data['email'];
        $this->username = (string) $data['username'];
    }
}

class PostDTO
{
    public int $id;
    public int $userId;
    public string $title;
    public string $body;

    public function __construct(array $data)
    {
        if (!isset($data['id'], $data['userId'], $data['title'], $data['body'])) {
            throw new InvalidArgumentException("Post data is incomplete.");
        }
        $this->id = (int) $data['id'];
        $this->userId = (int) $data['userId'];
        $this->title = (string) $data['title'];
        $this->body = (string) $data['body'];
    }
}

// --- 리포지토리 패턴: 데이터 접근 계층 추상화 ---
interface ExternalApiRepositoryInterface
{
    /**
     * @param string $endpoint 예: 'users', 'posts'
     * @param array $params 쿼리 파라미터
     * @return array 응답 데이터
     * @throws Exception API 통신 실패 시
     */
    public function fetchData(string $endpoint, array $params = []): array;

    /**
     * 여러 엔드포인트에서 데이터를 병렬로 가져옵니다.
     * @param array<string, array{endpoint: string, params: array}> $requests
     * @return array<string, array> Key-value pair of endpoint name and its data
     */
    public function fetchMultipleData(array $requests): array;
}

class JsonPlaceholderApiRepository implements ExternalApiRepositoryInterface
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * 단일 API 요청 (재시도 로직 포함)
     */
    public function fetchData(string $endpoint, array $params = []): array
    {
        $url = AppConfig::API_BASE_URL . '/' . $endpoint . '?' . http_build_query($params);
        $attempts = 0;

        while ($attempts < AppConfig::MAX_RETRIES) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, AppConfig::REQUEST_TIMEOUT_SEC);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($response !== false && $httpCode >= 200 && $httpCode < 300) {
                $data = json_decode($response, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $this->logger->info("데이터 성공적으로 가져옴: $endpoint", ['url' => $url]);
                    return $data;
                } else {
                    $this->logger->error("JSON 디코딩 실패 ($endpoint): " . json_last_error_msg(), ['url' => $url, 'response' => $response]);
                }
            } else {
                $this->logger->warning(
                    "API 요청 실패 ($endpoint): HTTP $httpCode, Error: '$error'",
                    ['url' => $url, 'attempt' => $attempts + 1]
                );
            }

            $attempts++;
            if ($attempts < AppConfig::MAX_RETRIES) {
                $delay = AppConfig::RETRY_BACKOFF_FACTOR_SEC * (2 ** ($attempts - 1));
                $this->logger->info("재시도 ($attempts/" . AppConfig::MAX_RETRIES . ") - $endpoint. {$delay}초 후 재시도...");
                usleep((int)($delay * 1_000_000)); // microseconds
            }
        }
        $this->logger->critical("최대 재시도 횟수 초과 - $endpoint 최종 실패.", ['url' => $url]);
        throw new RuntimeException("Failed to fetch data from $endpoint after " . AppConfig::MAX_RETRIES . " attempts.");
    }

    /**
     * curl_multi를 이용한 병렬 API 요청 (고성능 데이터 수집 시 핵심)
     */
    public function fetchMultipleData(array $requests): array
    {
        $mh = curl_multi_init();
        $channels = [];
        $results = [];

        foreach ($requests as $key => $req) {
            $url = AppConfig::API_BASE_URL . '/' . $req['endpoint'] . '?' . http_build_query($req['params']);
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, AppConfig::REQUEST_TIMEOUT_SEC);
            curl_multi_add_handle($mh, $ch);
            $channels[$key] = $ch;
            $results[$key] = null; // 초기화
        }

        $active = null;
        do {
            $mrc = curl_multi_exec($mh, $active);
        } while ($mrc == CURLM_CALL_MULTI_PERFORM);

        while ($active && $mrc == CURLM_OK) {
            if (curl_multi_select($mh) == -1) {
                // PHP bug workaround for curl_multi_select returning -1.
                // See https://www.php.net/manual/en/function.curl-multi-select.php#109765
                usleep(100);
            }
            do {
                $mrc = curl_multi_exec($mh, $active);
            } while ($mrc == CURLM_CALL_MULTI_PERFORM);
        }

        foreach ($channels as $key => $ch) {
            $response = curl_multi_getcontent($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_multi_remove_handle($mh, $ch);

            if ($response !== false && $httpCode >= 200 && $httpCode < 300) {
                $data = json_decode($response, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $results[$key] = $data;
                    $this->logger->info("데이터 (병렬) 성공적으로 가져옴: {$requests[$key]['endpoint']}");
                } else {
                    $this->logger->error("JSON 디코딩 실패 (병렬 {$requests[$key]['endpoint']}): " . json_last_error_msg(), ['response' => $response]);
                }
            } else {
                $this->logger->error(
                    "API 요청 (병렬 {$requests[$key]['endpoint']}) 실패: HTTP $httpCode, Error: '$error'",
                    ['url' => curl_getinfo($ch, CURLINFO_EFFECTIVE_URL)]
                );
            }
        }
        curl_multi_close($mh);
        return $results;
    }
}

// --- 서비스 계층: 비즈니스 로직 담당 ---
class DataIntegrationService
{
    private ExternalApiRepositoryInterface $apiRepository;
    private LoggerInterface $logger;

    public function __construct(ExternalApiRepositoryInterface $apiRepository, LoggerInterface $logger)
    {
        $this->apiRepository = $apiRepository;
        $this->logger = $logger;
    }

    /**
     * 여러 외부 API에서 데이터를 수집하고 전처리합니다.
     * @param array<string, array{endpoint: string, params: array}> $endpoints
     * @return array<string, array> 가공된 데이터
     */
    public function collectAndProcessData(array $endpoints): array
    {
        $this->logger->info("데이터 수집 및 전처리 시작.");
        $rawData = [];

        try {
            // 병렬 처리
            $rawData = $this->apiRepository->fetchMultipleData($endpoints);
            $this->logger->info("원시 데이터 수집 완료.");
        } catch (Exception $e) {
            $this->logger->critical("데이터 수집 중 치명적인 오류 발생: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            // 서비스 중단 또는 대체 로직 실행
            return ['error' => 'Data collection failed: ' . $e->getMessage()];
        }

        $processedData = [];
        foreach ($rawData as $key => $data) {
            if (empty($data)) {
                $this->logger->warning("엔드포인트 '$key'에서 데이터가 비어있습니다. 전처리 건너뜜.");
                continue;
            }

            try {
                switch ($key) {
                    case 'users':
                        $processedData[$key] = array_map(fn($item) => new UserDTO($item), $data);
                        $this->logger->info("사용자 데이터 전처리 완료. " . count($processedData[$key]) . "명.");
                        break;
                    case 'posts':
                        $processedData[$key] = array_map(fn($item) => new PostDTO($item), $data);
                        $this->logger->info("게시글 데이터 전처리 완료. " . count($processedData[$key]) . "개.");
                        break;
                    case 'comments':
                        // 댓글 데이터는 DTO로 변환하지 않고 단순히 저장 (예시)
                        $processedData[$key] = $data;
                        $this->logger->info("댓글 데이터 전처리 완료. " . count($processedData[$key]) . "개.");
                        break;
                    default:
                        $this->logger->warning("알 수 없는 데이터 유형 '$key'. 원시 데이터 저장.");
                        $processedData[$key] = $data;
                }
            } catch (InvalidArgumentException $e) {
                $this->logger->error("데이터 유효성 검사 실패 ($key): " . $e->getMessage());
                // 유효하지 않은 데이터는 처리하지 않거나, 오류 기록 후 건너뜀.
            } catch (Exception $e) {
                $this->logger->error("데이터 전처리 중 예기치 않은 오류 ($key): " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            }
        }
        $this->logger->info("데이터 전처리 완료.");
        return $processedData;
    }

    /**
     * 전처리된 데이터를 바탕으로 고급 분석을 수행합니다.
     * @param array $dataProcessed 전처리된 데이터
     * @return array 분석 결과
     */
    public function performAdvancedAnalysis(array $dataProcessed): array
    {
        $this->logger->info("고급 데이터 분석 시작.");
        $analysisResults = [];

        // 사용자별 게시글 수 분석
        if (isset($dataProcessed['users']) && isset($dataProcessed['posts'])) {
            $userPostCounts = [];
            foreach ($dataProcessed['posts'] as $post) {
                if ($post instanceof PostDTO) { // DTO 인스턴스 확인
                    $userId = $post->userId;
                    $userPostCounts[$userId] = ($userPostCounts[$userId] ?? 0) + 1;
                }
            }
            $analysisResults['user_post_counts'] = $userPostCounts;
            $this->logger->info("사용자별 게시글 수 분석 완료.");
        }

        // 특정 조건에 맞는 게시글 필터링 및 통계
        if (isset($dataProcessed['posts'])) {
            $longPosts = array_filter($dataProcessed['posts'], fn($post) => $post instanceof PostDTO && strlen($post->body) > 100);
            $analysisResults['long_posts_count'] = count($longPosts);
            $this->logger->info("긴 게시글 수 분석 완료.");
        }

        // 여러 데이터셋 간의 조인/결합 로직 (여기서는 간단화)
        // 실제로는 더 복잡한 매핑 및 결합 로직이 필요.
        if (isset($dataProcessed['users'], $analysisResults['user_post_counts'])) {
            $usersWithPostCounts = [];
            foreach ($dataProcessed['users'] as $user) {
                if ($user instanceof UserDTO) {
                    $usersWithPostCounts[] = [
                        'user_id' => $user->id,
                        'username' => $user->username,
                        'post_count' => $analysisResults['user_post_counts'][$user->id] ?? 0
                    ];
                }
            }
            // 가장 많은 게시글을 작성한 사용자 TOP 3
            usort($usersWithPostCounts, fn($a, $b) => $b['post_count'] <=> $a['post_count']);
            $analysisResults['top_3_users_by_posts'] = array_slice($usersWithPostCounts, 0, 3);
            $this->logger->info("가장 활동적인 사용자 TOP 3 분석 완료.");
        }

        $this->logger->info("고급 데이터 분석 완료.");
        return $analysisResults;
    }
}

// --- 애플리케이션 진입점 (Orchestrator 역할) ---
class Application
{
    private DataIntegrationService $dataService;
    private LoggerInterface $logger;

    public function __construct(DataIntegrationService $dataService, LoggerInterface $logger)
    {
        $this->dataService = $dataService;
        $this->logger = $logger;
    }

    public function run(): void
    {
        $startTime = microtime(true);
        $this->logger->info("--- 애플리케이션 시작 ---");

        $endpointsToFetch = [
            'users' => ['endpoint' => 'users', 'params' => ['_limit' => 5]],
            'posts' => ['endpoint' => 'posts', 'params' => ['_limit' => 10]],
            'comments' => ['endpoint' => 'comments', 'params' => ['postId' => 1, '_limit' => 3]], // 특정 게시글 댓글
        ];

        $processedData = $this->dataService->collectAndProcessData($endpointsToFetch);

        if (isset($processedData['error'])) {
            $this->logger->critical("파이프라인 실행 중단: " . $processedData['error']);
            return;
        }

        $analysisResults = $this->dataService->performAdvancedAnalysis($processedData);

        $endTime = microtime(true);
        $executionTime = round($endTime - $startTime, 2);

        $this->logger->info("--- 애플리케이션 완료 ---");
        $this->logger->info("총 실행 시간: {$executionTime}초");

        echo "\n### 최종 분석 결과 ###\n";
        echo json_encode($analysisResults, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    }
}

// --- 의존성 주입 및 애플리케이션 실행 ---
if (php_sapi_name() === 'cli') { // CLI 환경에서만 실행되도록
    $logger = new ConsoleLogger();
    $apiRepository = new JsonPlaceholderApiRepository($logger);
    $dataService = new DataIntegrationService($apiRepository, $logger);
    $app = new Application($dataService, $logger);

    $app->run();
} else {
    echo "이 스크립트는 CLI 환경에서 실행되어야 합니다.\n";
}
