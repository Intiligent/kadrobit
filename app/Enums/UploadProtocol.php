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
}
