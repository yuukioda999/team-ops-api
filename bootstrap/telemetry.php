<?php

/**
 * トレースを送る前に個人情報を伏せる(ADR-0026、AC-010-01)。
 *
 * OpenTelemetry の SDK は、環境変数(OTEL_PHP_AUTOLOAD_ENABLED など)から送り口を作り、自動計装を
 * 組み込む(vendor の SdkAutoloader)。ここでは、その後にもう1つ初期化の処理を足し、トレースの
 * 送り口を「伏せてから OTLP で送る」ものに差し替える。自動計装は、使う時点で Globals の送り口を
 * 見るので、差し替えたものが使われる。
 *
 * composer.json の autoload.files で読み込む(ルートのパッケージのファイルは vendor のあとに読まれる)。
 */

use App\Support\Telemetry\RedactingSpanExporter;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\Contrib\Otlp\SpanExporterFactory;
use OpenTelemetry\SDK\Common\Configuration\Configuration;
use OpenTelemetry\SDK\Common\Configuration\Variables;
use OpenTelemetry\SDK\Common\Util\ShutdownHandler;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\Trace\SamplerFactory;
use OpenTelemetry\SDK\Trace\SpanProcessorFactory;
use OpenTelemetry\SDK\Trace\TracerProviderBuilder;

if (! class_exists(Sdk::class) || ! class_exists(SpanExporterFactory::class)) {
    return;
}
if (! Configuration::getBoolean(Variables::OTEL_PHP_AUTOLOAD_ENABLED) || Sdk::isDisabled()) {
    return;
}

Globals::registerInitializer(static function (Configurator $configurator): Configurator {
    $exporter = new RedactingSpanExporter((new SpanExporterFactory)->create());
    $tracerProvider = (new TracerProviderBuilder)
        ->addSpanProcessor((new SpanProcessorFactory)->create($exporter))
        ->setResource(ResourceInfoFactory::defaultResource())
        ->setSampler((new SamplerFactory)->create())
        ->build();
    ShutdownHandler::register($tracerProvider->shutdown(...));

    return $configurator->withTracerProvider($tracerProvider);
});
