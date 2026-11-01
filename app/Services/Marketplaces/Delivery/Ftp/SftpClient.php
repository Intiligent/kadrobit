<?php

namespace App\Services\Marketplaces\Delivery\Ftp;

/**
 * One SFTP connection, behind the same calls as FTP (PhpseclibSftpClient is the real one): FtpDelivery picks it for
 * a marketplace whose upload_protocol is sftp. connect() ignores $ssl: SFTP is always encrypted.
 */
interface SftpClient extends FtpClient {}
