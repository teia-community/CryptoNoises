<?php

namespace CryptoNoisesTezos\Entities;

use CryptoNoisesTezos\Config;
use CryptoNoisesTezos\Core\Downloader;
use CryptoNoisesTezos\Core\MediaTool;
use CryptoNoisesTezos\Core\Log;
use CryptoNoisesTezos\Models;
use CryptoNoisesTezos\Vendors\Ipfs;
use CryptoCore\Time;
use Exception;

class IpfsMedia
{
    const kTYPE_Image               = 'image';
    const kTYPE_Animation           = 'animation';
    const kTYPE_Video               = 'video';
    const kTYPE_NotSupported        = 'not-supported';
    const kTYPE_Undefined           = 'undefined';

    const kTYPE_VIRTUAL_Corrupted   = 'corrupted';

    // size of the text/html error page some IPFS gateways return instead of the file
    const kGATEWAY_ERROR_PAGE_SIZE  = 166;

    //------------------------------------------------------------------------------------------------------------------

    public string $ipfsUri;
    public ?string $storageFolder = null;

    public ?string $mediaType = null;
    public ?int $width = null;
    public ?int $height = null;
    public ?int $filesize = null;
    public ?string $mimetype = null;
    public ?bool $hasThumbnail = null;
    public ?bool $hasAnimation = null;
    public ?bool $hasMovieClip = null;

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    public ?bool $lastResult = null;
    public ?string $lastActionNotice = null;

    // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

    private ?IpfsMediaStorageAttrs $storageAttrs = null;

    //------------------------------------------------------------------------------------------------------------------

    protected function __construct(string $mediaUri)
    {
        $this->ipfsUri = $mediaUri;
    }

    public static function Get(?string $mediaUri): ?self
    {
        if ($mediaUri == null)
            return null;

        $item = Models\IpfsMedia::model()->getItem($mediaUri);

        if ($item == null)
            return null;

        $instance = new self($mediaUri);
        $instance->initialize($item);
        return $instance;
    }

    public static function Dummy(string $mediaUri): self
    {
        return new self($mediaUri);
    }

    //------------------------------------------------------------------------------------------------------------------

    public function initialize(array $data): void
    {
        $this->mediaType    = $data['media_type'];
        $this->width        = $data['width'];
        $this->height       = $data['height'];
        $this->filesize     = $data['filesize'];
        $this->mimetype     = $data['mimetype'];
        $this->hasThumbnail = $data['has_thumbnail'] !== null ? (bool) $data['has_thumbnail'] : null;
        $this->hasAnimation = $data['has_animation'] !== null ? (bool) $data['has_animation'] : null;
        $this->hasMovieClip = $data['has_movieclip'] !== null ? (bool) $data['has_movieclip'] : null;
    }

    //------------------------------------------------------------------------------------------------------------------

    public function save()
    {
        $data = [
            'media_type'        => $this->mediaType,
            'width'             => $this->width,
            'height'            => $this->height,
            'filesize'          => $this->filesize,
            'mimetype'          => $this->mimetype,
            'has_thumbnail'     => $this->hasThumbnail,
            'has_animation'     => $this->hasAnimation,
            'has_movieclip'     => $this->hasMovieClip,

            'storage_filepath'  => $this->getStorageAttrs()->fullpath,
        ];

        Models\IpfsMedia::model()->save($this->ipfsUri, $data);
    }

    //------------------------------------------------------------------------------------------------------------------

    public function getStorageAttrs(): IpfsMediaStorageAttrs
    {
        if ($this->storageAttrs === null)
            $this->storageAttrs = new IpfsMediaStorageAttrs($this->ipfsUri, $this->storageFolder);

        return $this->storageAttrs;
    }

    public function getSaveFullPath(): string
    {
        $savePreviewPath = Config::cfg()->ipfsLocalBasePath.'/'.$this->getStorageAttrs()->pathName;
        $savePreviewName = $this->getStorageAttrs()->fileName;
        $savePreviewFull = $savePreviewPath.'/'.$savePreviewName;

        if (!is_dir($savePreviewPath))
            @mkdir($savePreviewPath, 0755, true);

        return $savePreviewFull;
    }

    public function getPublicUrl($extension): string
    {
        return Config::cfg()->ipfsLocalBaseUrl.'/'.$this->getStorageAttrs()->fullpath.'.'.$extension;
    }

    //------------------------------------------------------------------------------------------------------------------

    public function download(?int $uniqueTaskId = null): bool
    {
        $this->lastResult = null;
        $this->lastActionNotice = null;

        $downloader = null;

        if ($uniqueTaskId == null)
            $uniqueTaskId = Time::Time().rand(100000,999999);

        try
        {
            //----------------------------------------------------------------------------------------------------------
            // Prepare paths / filenames

            $ipfsSafeFileName = Ipfs::GetSafeFilename($this->ipfsUri);

            if ($ipfsSafeFileName == null)
                throw new Exception('Cannot generate $ipfsSafeFileName: '.$this->ipfsUri);

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            // Through the fetch gateway first when one is set, the public gateway after it
            $mediaUrls = Ipfs::FetchUrls($this->ipfsUri);

            if (!$mediaUrls)
                throw new Exception('Cannot generate $mediaUrl');

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            $savePreviewFull = $this->getSaveFullPath();

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            $saveOriginPath  = Config::cfg()->privateStorageBasePath.'/preview-origins';
            $saveOriginName  = $uniqueTaskId.'-'.$ipfsSafeFileName;

            $saveCachePath   = Config::cfg()->privateStorageBasePath.'/ipfs-cache';
            $saveCacheName   = $ipfsSafeFileName;

            if (!is_dir($saveOriginPath))
                @mkdir($saveOriginPath, 0755, true);

            //----------------------------------------------------------------------------------------------------------
            // Download file

            $saved = false;

            foreach ($mediaUrls as $mediaUrl)
            {
                $mediaUrl   = Ipfs::AppendUniqueTaskIdToUrl($mediaUrl, $uniqueTaskId);
                $downloader = new Downloader($mediaUrl, $saveOriginPath, $saveOriginName);
                $downloader->setCacheFileId($saveCachePath, $saveCacheName);

                if ($saved = $downloader->save())
                    break;
            }

            if (!$saved)
                throw new Exception("Failed to download file: $mediaUrl");

            //----------------------------------------------------------------------------------------------------------
            // Detect type / mimetype / params

            $originFile = $downloader->getFullPathName();

            $this->mimetype = mime_content_type($originFile);
            $this->width    = null;
            $this->height   = null;
            $this->filesize = filesize($originFile);

            $mimetypeItem = Models\Mimetypes::model()->getItemByMimetype($this->mimetype);

            if ($mimetypeItem)
            {
                // Keep the original type: an animated GIF must stay 'animation', not 'image',
                // otherwise the preview is made from the whole frame sequence, not from a single image
                switch ($mimetypeItem['preview_type'])
                {
                    case 'image':         $this->mediaType = self::kTYPE_Image; break;
                    case 'animation':     $this->mediaType = $this->isGifAnimated($originFile) ? self::kTYPE_Animation : self::kTYPE_Image; break;
                    case 'video':         $this->mediaType = self::kTYPE_Video; break;
                    case 'not-supported': $this->mediaType = self::kTYPE_NotSupported; break;
                    default:              $this->mediaType = self::kTYPE_Undefined; break;
                }

                // Formats without a safe ImageMagick coder (SVG and unknown ones) are not rendered
                if (($this->mediaType == self::kTYPE_Image || $this->mediaType == self::kTYPE_Animation) &&
                    MediaTool::CoderByMimetype($this->mimetype) === null)
                    $this->mediaType = self::kTYPE_NotSupported;

                if ($this->mediaType == self::kTYPE_Image || $this->mediaType == self::kTYPE_Animation)
                {
                    list($this->width, $this->height) = getimagesize($originFile);

                    if ($this->width > 8192 || $this->height > 8192)
                        $this->mediaType = self::kTYPE_NotSupported;
                }

                if ($this->mediaType == self::kTYPE_NotSupported || $this->mediaType == self::kTYPE_Undefined)
                {
                    if ($this->filesize <= 4096 && strpos(file_get_contents($originFile), '504 Gateway Time-out') !== false)
                        $this->mediaType = self::kTYPE_VIRTUAL_Corrupted;

                    // 166-byte text/html response is a gateway error page
                    if ($this->filesize == self::kGATEWAY_ERROR_PAGE_SIZE && $this->mimetype == 'text/html')
                        $this->mediaType = self::kTYPE_VIRTUAL_Corrupted;
                }
            }
            else
            {
                Models\Mimetypes::model()->insert($this->mimetype, 'AutoInserted');

                Log::Warning(get_class($this), __FUNCTION__, "Found new mimetype [$this->mimetype]. preview_type must be defined");

                $this->mediaType = self::kTYPE_Undefined;
            }

            //----------------------------------------------------------------------------------------------------------

            if ($this->mediaType == self::kTYPE_VIRTUAL_Corrupted)
            {
                $downloader->removeCache();
                throw new Exception("Preview corrupted: $mediaUrl");
            }

            // - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

            $this->hasThumbnail = false;
            $this->hasAnimation = false;
            $this->hasMovieClip = false;

            if ($this->mediaType == self::kTYPE_NotSupported)
            {
                $downloader->removeCache();
            }
            //----------------------------------------------------------------------------------------------------------
            else if ($this->mediaType == self::kTYPE_Video)
            {
                if ($this->createThumbnailFromVideo($originFile, "$savePreviewFull.jpg") == false)
                    throw new Exception("Cannot create image-preview: $mediaUrl");

                $this->hasThumbnail = true;


                $this->createMotionPreviews($originFile, $savePreviewFull, $mediaUrl);
            }
            //----------------------------------------------------------------------------------------------------------
            else if ($this->mediaType == self::kTYPE_Animation)
            {
                if ($this->createThumbnailFromImage($originFile.'[0]', "$savePreviewFull.jpg") == false)
                    throw new Exception("Cannot create image-preview: $mediaUrl");

                $this->hasThumbnail = true;


                $this->createMotionPreviews($originFile, $savePreviewFull, $mediaUrl);
            }
            //----------------------------------------------------------------------------------------------------------
            else if ($this->mediaType == self::kTYPE_Image)
            {
                if ($this->createThumbnailFromImage($originFile, "$savePreviewFull.jpg") == false)
                    throw new Exception("Cannot create image-preview: $mediaUrl");

                $this->hasThumbnail = true;
            }
            //----------------------------------------------------------------------------------------------------------
            else
                throw new Exception("Unknown source type: $this->mediaType");

            $downloader->delete();

            $this->save();

            $this->lastResult = true;
            return true;
        }
        catch (Exception $e)
        {
            if ($downloader instanceof Downloader)
                $downloader->delete();

            $this->lastResult = false;
            $this->lastActionNotice = $e->getMessage();
            return false;
        }
    }

    /**
     * Optional mp4 / GIF previews (off by default, see tezos.media in environment.php).
     *
     * @throws Exception
     */
    private function createMotionPreviews(string $originFile, string $savePreviewFull, string $mediaUrl): void
    {
        if (Config::cfg()->isTokenSupportsVideoPreview())
        {
            if ($this->createVideoPreview($originFile, "$savePreviewFull.mp4") == false)
                throw new Exception("Cannot create video-preview: $mediaUrl");

            $this->hasMovieClip = true;
        }

        if ($this->hasMovieClip && Config::cfg()->isTokenSupportsAnimatedPreview())
        {
            if ($this->createAnimationPreview("$savePreviewFull.mp4", "$savePreviewFull.gif") == false)
                throw new Exception("Cannot create animated-preview from video: $mediaUrl");

            $this->hasAnimation = true;
        }
    }

    //------------------------------------------------------------------------------------------------------------------
    // Converters

    private function createThumbnailFromImage($inFile, $outFile, ?string $mimetype = null): bool
    {
        $media = Config::cfg()->media;

        // Only known raster formats are rendered (SVG is not: it may reference local files)
        $coder = MediaTool::CoderByMimetype($mimetype ?? $this->mimetype);

        if ($coder === null)
            return false;

        if ($this->width > $media->thumbnailWidth)
            $cmdResize = '-resize '.$media->thumbnailWidth.'x';
        else
            $cmdResize = '';

        MediaTool::Convert($coder, $inFile, $outFile, "$cmdResize -format jpg -quality {$media->thumbnailQuality}%");

        return $this->isFileExistsAndNotEmpty($outFile);
    }

    private function createThumbnailFromVideo($inFile, $outFile): bool
    {
        $outFileTmp = $inFile.'_thumb.png';

        MediaTool::Ffmpeg($inFile, $outFileTmp, '-ss 0 -vframes 1'); // 1) extract 1 frame (offset 0 sec) for preview

        if (!$this->isFileExistsAndNotEmpty($outFileTmp))
            return false;

        if ($this->width == null || $this->height == null)
            list($this->width, $this->height) = getimagesize($outFileTmp);

        $result = $this->createThumbnailFromImage($outFileTmp, $outFile, 'image/png');

        unlink($outFileTmp);

        return $result;
    }

    private function createVideoPreview($inFile, $outFile): bool
    {
        if ($this->width > 0 && $this->height > 0)
        {
            $newWidth  = min($this->width, Config::cfg()->media->videoWidth);
            $newWidth -= $newWidth%2; // make it even!

            $newHeight = floor($this->height * ($newWidth/$this->width));
            $newHeight-= $newHeight%2; // make it even!

            $cmdResize = "-vf scale=$newWidth:$newHeight";
        }
        else
            $cmdResize = '';

        // important "-pix_fmt yuv420p" to avoid color shift!
        MediaTool::Ffmpeg($inFile, $outFile, "-c:v libx264 -pix_fmt yuv420p -b:v ".Config::cfg()->media->videoBitrate." $cmdResize");

        return $this->isFileExistsAndNotEmpty($outFile);
    }

    private function createAnimationPreview($inFile, $outFile): bool
    {
        $media    = Config::cfg()->media;
        $newWidth = $this->width ? min($this->width, $media->animatedWidth) : $media->animatedWidth;
        $fps      = $media->animatedFps;

        MediaTool::Ffmpeg($inFile, $outFile,
            "-vf 'fps=$fps,scale=$newWidth:-1:flags=lanczos,split[s0][s1];[s0]palettegen[p];[s1][p]paletteuse' -loop 0");

        return $this->isFileExistsAndNotEmpty($outFile);
    }

    //------------------------------------------------------------------------------------------------------------------
    // Helpers

    private function isGifAnimated(string $source): bool
    {
        if(!($fh = @fopen($source, 'rb')))
            return false;

        $count = 0;
        //an animated gif contains multiple "frames", with each frame having a
        //header made up of:
        // * a static 4-byte sequence (\x00\x21\xF9\x04)
        // * 4 variable bytes
        // * a static 2-byte sequence (\x00\x2C)

        // We read through the file til we reach the end of the file, or we've found
        // at least 2 frame headers
        while(!feof($fh) && $count < 2)
        {
            $chunk = fread($fh, 1024 * 100); //read 100kb at a time
            $count += preg_match_all('#\x00\x21\xF9\x04.{4}\x00[\x2C\x21]#s', $chunk,$matches);
        }

        fclose($fh);
        return $count > 1;
    }

    private function isFileExistsAndNotEmpty(string $source): bool
    {
        if (!file_exists($source))
            return false;

        if (filesize($source) == 0)
        {
            unlink($source);
            return false;
        }
        return true;
    }
}
