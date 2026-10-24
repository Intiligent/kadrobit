<?php

namespace App\Enums;

enum UploadProtocol: string
{
    case Ftp = 'ftp';
    case Ftps = 'ftps';
    case Sftp = 'sftp';
    case Api = 'api';

    /** No automated upload: only via the marketplace web portal. */
    case Web = 'web';

    /**
     * Protocols Kadrobit uploads files over itself: marketplaces with them can be connected in settings.
     *
     * @return list<self>
     */
    public static function fileTransfer(): array
    {
        return [self::Ftp, self::Ftps, self::Sftp];
    }
}
