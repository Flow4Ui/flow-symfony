<?php

namespace Flow\Template;

enum PreprocessorState
{
    case TagName;
    case TagNameClosing;
    case AttributeName;
    case Equal;
    case AttributeValue;
    case Text;
    case Expression;
    case Whitespace;
    case Comment;
}
