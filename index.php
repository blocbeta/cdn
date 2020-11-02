<?php

use Aws\S3\Exception\S3Exception;
use League\Glide\Filesystem\FileNotFoundException;
use League\Glide\Responses\ResponseFactoryInterface;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\RouteCollectionBuilder;
use Aws\S3\S3Client;
use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem;
use League\Glide\ServerFactory;
use League\Flysystem\AwsS3v3\AwsS3Adapter;
use League\Flysystem\FilesystemInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

require __DIR__ . "/vendor/autoload.php";

class AdResponseFactory implements ResponseFactoryInterface
{
    protected $request;

    public function __construct(Request $request = null)
    {
        $this->request = $request;
    }

    /**
     * @param FilesystemInterface $cache
     * @param string $path
     * @return mixed|StreamedResponse
     * @throws \League\Flysystem\FileNotFoundException
     */
    public function create(FilesystemInterface $cache, $path)
    {
        $stream = $cache->readStream($path);

        $response = new StreamedResponse();
        $response->headers->set("Content-Type", $cache->getMimetype($path));
        $response->headers->set("Content-Length", $cache->getSize($path));
        $response->headers->set("Access-Control-Allow-Origin", "*");

        $response->setPublic();
        $response->setMaxAge(31536000);
        $response->setExpires(date_create()->modify("+1 years"));

        if ($this->request) {
            $response->setLastModified(date_create()->setTimestamp($cache->getTimestamp($path)));
            $response->isNotModified($this->request);
        }

        $response->setCallback(function () use ($stream) {
            if (ftell($stream) !== 0) {
                rewind($stream);
            }
            fpassthru($stream);
            fclose($stream);
        });

        return $response;
    }
}

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function registerBundles()
    {
        return [
            new Symfony\Bundle\FrameworkBundle\FrameworkBundle()
        ];
    }

    protected function configureContainer(ContainerBuilder $c, LoaderInterface $loader)
    {
    }

    protected function configureRoutes(RouteCollectionBuilder $routes)
    {
        // kernel is a service that points to this class
        // optional 3rd argument is the route name
        $routes->add("/{bucket}/{path<.*>?}", "kernel::serve");
    }

    public function serve(Request $request, string $bucket, string $path)
    {
        $params = $request->query->all();
        $server = $this->getServer($bucket);

        if (!$params) {
            return $server->getResponseFactory()->create($server->getSource(), $path);
        }

        try {
            return $this->getServer($bucket)->getImageResponse($path, $params);

        } catch (FileNotFoundException $exception) {

            return new JsonResponse([
                "message" => "File {$path} not found",
                "code" => Response::HTTP_NOT_FOUND
            ], Response::HTTP_NOT_FOUND);

        } catch (S3Exception $exception) {

            if ($exception->getAwsErrorCode() === "NoSuchBucket") {

                return new JsonResponse([
                    "message" => "Bucket {$bucket} does not exist",
                    "code" => Response::HTTP_NOT_FOUND
                ], Response::HTTP_NOT_FOUND);
            }

        } catch (\Exception $exception) {

            return new JsonResponse([
                "message" => "Something went wrong.",
                "code" => Response::HTTP_INTERNAL_SERVER_ERROR
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function getServer(string $bucket)
    {
        $client = new S3Client([
            "version" => "latest",
            "region" => "us-east-1",
            "endpoint" => "https://cdn.disko.io",
            "use_path_style_endpoint" => true,
            "credentials" => [
                "key" => $_ENV["MINIO_KEY"],
                "secret" => $_ENV["MINIO_SECRET"],
            ],
        ]);

        $minio = new AwsS3Adapter($client, $bucket);

        return ServerFactory::create([
            "response" => new AdResponseFactory(),
            "driver" => "imagick",
            "source" => new Filesystem($minio),
            "cache" => new Filesystem(new Local(__DIR__ . "/var/cache/img"))
        ]);
    }
}

(new Dotenv(false))->loadEnv(".env");

$kernel = new Kernel($_ENV["ENV"], $_ENV["DEBUG"]);
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
