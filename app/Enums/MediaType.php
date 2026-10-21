<?php

namespace App\Enums;

enum MediaType: string
{
    case Photo = 'photo';
    case Video = 'video';
    case Vector = 'vector';
    case Illustration = 'illustration';
}
