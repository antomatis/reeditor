<?php
require_once(__DIR__ . '/vendor/autoload.php');

class Reeditor
{
    public $imageLibrary;
    public $image;

    function __construct($imageStoragePath = __DIR__ . '/lib/img/results/')
    {
        $this->imageLibrary = $imageStoragePath;
    }

    function storyToImages($reddit_url, $max_comment_count, $target_image_count)
    {
        $redditApiOutput = json_decode(file_get_contents($reddit_url . '.json'), 1);
        $imageFiles = [];
        //-- TEXT --//
        $text = $redditApiOutput[0]['data']['children'][0]['data']['selftext'];
        $textHeadline = $redditApiOutput[0]['data']['children'][0]['data']['title'];
        $textParts = $this->splitTextToParts($target_image_count, $text);
        foreach ($textParts as $key => $textPart) {
            $imageFiles[] = $this->createImageFromText($textPart, $key ? 0 : $textHeadline);
        }
        //-- COMMENTS --//
        $comments = $this->parseComments(
            $redditApiOutput[1]['data']['children'],
            $max_comment_count);
        foreach ($comments as $comment) {
            $imageFiles[] = $this->createImageFromComment($comment);
        }
        return $imageFiles;
    }

    function parseComments($comments, $max_comment_count = null)
    {
        //create two groups for comments with/without a reply from OP
        $CommentsWithoutReply = [];
        $CommentsWithReply = [];
        foreach ($comments as $comment) {
            if (!isset($comment['data']['body'])) {
                continue;
            }
            $tmp = null;
            $tmp['text'] = $this->replaceProfanities($comment['data']['body']);
            $tmp['author'] = ($comment['data']['author'] == '[deleted]') ? 'anon' : $comment['data']['author'];
            //only display >2000 year dating
            if ($comment['data']['created'] > 955694212) {
                $tmp['created_ago'] = $this->time_elapsed_string($comment['data']['created']);
            } else {
                $tmp['created_ago'] = "";
            }
            $tmp['points'] = $comment['data']['ups'];
            $tmp['points_text'] = $this->thousandsFormat($comment['data']['ups']);
            if (@is_array($comment['data']['replies']['data']['children'])) {
                foreach ($comment['data']['replies']['data']['children'] as $reply) {
                    if (@$reply['data']['is_submitter']) {
                        $tmp['op_reply']['author'] =
                            ($reply['data']['author'] == '[deleted]') ? 'anon' : $reply['data']['author'];
                        $tmp['op_reply']['text'] = $this->replaceProfanities($reply['data']['body']);
                        $tmp['op_reply']['created_ago'] = $this->time_elapsed_string(
                            $reply['data']['created']);
                        $tmp['op_reply']['points'] = $reply['data']['ups'];
                        $tmp['op_reply']['points_text'] = $this->thousandsFormat($reply['data']['ups']);
                    }
                }
            }
            isset($tmp['op_reply']) ? $CommentsWithReply[] = $tmp : $CommentsWithoutReply[] = $tmp;
        }
        uasort($CommentsWithReply, function ($a, $b) {
            return $b['points'] + $b['op_reply']['points'] <=> $a['points'] + $a['op_reply']['points'];
        });
        uasort($CommentsWithoutReply, function ($a, $b) {
            return $b['points'] <=> $a['points'];
        });

        //(!) merge order is important
        $output = array_merge($CommentsWithReply, $CommentsWithoutReply);
        //limit comment count to max_comment_count variable
        if ($max_comment_count) {
            $output = array_slice($output, 0, $max_comment_count);
        }
        return $output;
    }

    function splitTextToParts($target_image_count, $text)
    {
        $text = $this->replaceProfanities($text);
        $paragraphs = explode("\n\n", $text);
        //In case need LESS images than have paragraphs
        while ($target_image_count < count($paragraphs)) {
            //combine the shortest paragraph with the previous one
            foreach ($paragraphs as $key => $paragraph) {
                if (min(array_map('strlen', $paragraphs)) == strlen($paragraph)) {
                    if ($key !== 0) {
                        $paragraphs[$key - 1] .= "\n\n" . $paragraph;
                        unset($paragraphs[$key]);
                    } else {
                        $paragraphs[$key] .= "\n\n" . $paragraphs[$key + 1];
                        unset($paragraphs[$key + 1]);
                    }
                    break;
                }
            }
        }
        //In case need MORE images than have paragraphs
        while ($target_image_count > count($paragraphs)) {
            //sort by paragraph length
            uasort($paragraphs, function ($a, $b) {
                return strlen($b) <=> strlen($a);
            });
            //split the longest paragraph
            foreach ($paragraphs as $key => $paragraph) {
                //get sentences
                preg_match_all('~.{50,999}?[?.!]{1,10}~s', $paragraph, $sentences);
                $sentences = $sentences[0];
                //need at least 2 sentences
                if (count($sentences) < 2) {
                    //echo 'too few sentences for further splitting - lets return what we have' . "\n";
                    //return to normal order
                    ksort($paragraphs);
                    return $paragraphs;
                }
                //split the longest paragraph into two after the middle sentence
                $middleSentence = $sentences[round(count($sentences) / 2)];
                $beginning = explode($middleSentence, $paragraph)[0];
                $ending = str_replace($beginning, '', $paragraph);
                $paragraph = null;
                $paragraph[] = $beginning;
                $paragraph[] = $ending;
                $paragraphs[$key] = $paragraph;
                break;
            }
            //return to normal order before flattening
            ksort($paragraphs);
            //flatten array to combine smaller parts
            $paragraphs = $this->flatten($paragraphs);
        }


        return $paragraphs;
    }

    /**
     * @throws ImagickException
     * @throws ImagickDrawException
     */
    function createImageFromText($text, $headerText = null)
    {
        //set common spacing
        $margin['left'] = 43;
        //track drawings positions
        $pos = ['x' => 0, 'y' => 0];

        $this->image = new Imagick();
        $this->image->newImage(700, 10000, new ImagickPixel('#F9F9F9'));
        if ($headerText) {
            $pos['y'] += 43;
            //Header Text//
            $pos['y'] += $this->drawMainText(
                __DIR__ . '/lib/fonts/verdana_bold.ttf',
                '#929295',
                25,
                37,
                $headerText,
                590,
                $margin['left'],
                20
            )['height'];
        } else {
            $pos['y'] += 15;
        }
        $paragraphs = explode("\n\n", $text);

        foreach ($paragraphs as $key => $paragraph) {
            $pos['y'] += $this->drawMainText(
                __DIR__ . '/lib/fonts/verdana.ttf',
                'black',
                29.3,
                54,
                $paragraph,
                625,
                $margin['left'],
                $pos['y'] - 15
            )['height'];
            if (count($paragraphs) >  $key +1) {
                $pos['y'] += 58;
            }
        }
        $this->image->cropImage(700, $pos['y'] + 25, 0, 0);
        $this->image->borderImage('#E3E3E3', 1, 1);
        $this->image->setImageFormat('png');
        $imageFile = $this->imageLibrary . 'author_'. md5(microtime(true)) . '.png';
        file_put_contents($imageFile, $this->image);
        return $imageFile;
    }

    /**
     * @throws ImagickException
     * @throws ImagickDrawException
     * @throws ImagickPixelException
     */
    function createImageFromComment($comment)
    {
        //set common spacing
        $margin['left'] = 21;
        $margin['top'] = 18;
        $margin['after_author'] = 10;
        //track drawings positions
        $pos = ['x' => 0, 'y' => 0];

        $this->image = new Imagick();
        $this->image->newImage(700, 10000, new ImagickPixel('#FFFFFF'));

        //Comment Author line//
        $pos['x'] += $this->drawCommentAuthor(
            $margin['left'],
            $margin['top'],
            $comment['author'],
            0
        )['width'];

        //Comment Meta//
        $pos['y'] += $this->drawCommentMeta(
            $margin['left'] + 30 + $pos['x'],
            $margin['top'],
            $comment['points_text'] . ' points ' . $comment['created_ago']
        )['height'];
        $pos['x'] = 0;

        $pos['y'] += $margin['after_author'];
        //Comment Text//
        $pos['y'] += $this->drawMainText(
            __DIR__ . '/lib/fonts/noto_sans_regular.ttf',
            'black',
            28,
            42,
            $comment['text'],
            640,
            $margin['left'],
            $pos['y']
        )['height'];

        //IF WE HAVE OP REPLY //
        if (isset($comment['op_reply'])) {
            $pos['y'] += 10;
            $startLineAtY = $pos['y'];
            //OP Reply Author line//
            $pos['x'] += $this->drawCommentAuthor(
                $margin['left'] * 2,
                $pos['y'] + $margin['top'],
                $comment['op_reply']['author'] . ' (OP)',
                1
            )['width'];
            //OP Reply Comment Meta//
            $pos['y'] += $this->drawCommentMeta(
                $margin['left'] * 2 + 30 + $pos['x'],
                $pos['y'] + $margin['top'],
                $comment['op_reply']['points_text'] . ' points ' . $comment['op_reply']['created_ago']
            )['height'];
            $pos['x'] = 0;
            $pos['y'] += $margin['after_author'];
            //OP Reply Comment Text//
            $pos['y'] += $this->drawMainText(
                __DIR__ . '/lib/fonts/noto_sans_regular.ttf',
                'black',
                28,
                42,
                $comment['op_reply']['text'],
                640,
                $margin['left'] * 2,
                $pos['y']
            )['height'];
            //OP Reply Vertical Line
            $this->drawCommentLine(
                $margin['left'], $startLineAtY + 38 - 10, $margin['left'], $pos['y']);
        }
        $this->image->cropImage(700, $pos['y'] + $margin['top'], 0, 0);
        $this->image->borderImage('#E3E3E3', 1, 1);
        $this->image->setImageFormat('png');
        $imageFile = $this->imageLibrary . 'comment_'. md5(microtime(true)) . '.png';
        file_put_contents($imageFile, $this->image);
        return $imageFile;
    }

    function flatten(array $array)
    {
        $return = array();
        array_walk_recursive($array, function ($a) use (&$return) {
            $return[] = $a;
        });
        return $return;
    }

    /*
    Make sure to set the font on the ImagickDraw Object first!
    @param image the Imagick Image Object
    @param draw the ImagickDraw Object
    @param text the text you want to wrap
    @param maxWidth the maximum width in pixels for your wrapped "virtual" text box
    @return an array of lines and line heights
    */
    function wordWrapAnnotation($image, $draw, $text, $maxWidth)
    {
        $text = trim($text);
        $words = preg_split('%\s%', $text, -1, PREG_SPLIT_NO_EMPTY);
        $lines = array();
        $i = 0;
        while (count($words) > 0) {
            $metrics = $image->queryFontMetrics($draw,
                implode(' ', array_slice($words, 0, ++$i)));

            // check if we have found the word that exceeds the line width
            if ($metrics['textWidth'] > $maxWidth or count($words) < $i) {
                // handle case where a single word is longer
                // than the allowed line width (just add this as a word on its own line?)
                if ($i == 1)
                    $i++;

                $lines[] = implode(' ', array_slice($words, 0, --$i));
                $words = array_slice($words, $i);
                $i = 0;
            }
        }

        return $lines;
    }

    /**
     * @throws Exception
     */
    function time_elapsed_string($datetime, $full = false)
    {
        $now = new DateTime;
        $ago = new DateTime(date('Y-m-d H:i:s', $datetime));
        $diff = $now->diff($ago);

        $diff->w = floor($diff->d / 7);
        $diff->d -= $diff->w * 7;

        $string = array(
            'y' => 'year',
            'm' => 'month',
            'w' => 'week',
            'd' => 'day',
//            'h' => 'hour',
//            'i' => 'minute',
//            's' => 'second',
        );
        foreach ($string as $k => &$v) {
            if ($diff->$k) {
                $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
            } else {
                unset($string[$k]);
            }
        }

        if (!$full) $string = array_slice($string, 0, 1);
        return $string ? implode(', ', $string) . ' ago' : 'just now';
    }

    function thousandsFormat($num)
    {

        if ($num > 1000) {

            $x = round($num);
            $x_number_format = number_format($x);
            $x_array = explode(',', $x_number_format);
            $x_parts = array('k', 'm', 'b', 't');
            $x_count_parts = count($x_array) - 1;
            $x_display = $x;
            $x_display = $x_array[0] . ((int)$x_array[1][0] !== 0 ? '.' . $x_array[1][0] : '');
            $x_display .= $x_parts[$x_count_parts - 1];

            return $x_display;

        }

        return $num;
    }

    /**
     * @throws ImagickDrawException
     */
    function drawCommentLine($sx, $sy, $ex, $ey)
    {
        $drawLine = new \ImagickDraw();
        $drawLine->setStrokeColor(new ImagickPixel('#E3E3E3'));
        $drawLine->setFillColor(new ImagickPixel('#E3E3E3'));
        $drawLine->setStrokeWidth(1.5);
        $drawLine->line(
            $sx,
            $sy,
            $ex,
            $ey
        );
        $this->image->drawImage($drawLine);
    }

    /**
     * @throws ImagickException
     * @throws ImagickDrawException
     */
    function drawMainText($font, $fontColor, $fontSize, $lineHeight, $text, $maxWidth, $x, $y)
    {
        $drawing['height'] = 0;
        $draw = new ImagickDraw();
        $draw->setFont($font);
        $draw->setFillColor(new ImagickPixel($fontColor));
        $draw->setFontSize($fontSize);
//        $draw->setTextKerning(0); might use later
        $lines = $this->wordWrapAnnotation($this->image, $draw, $text, $maxWidth);
        for ($i = 0; $i < count($lines); $i++) {
            $this->image->annotateImage($draw, $x, $y + ($i + 1) * $lineHeight, 0, $lines[$i]);
            $drawing['height'] += $lineHeight;
        }
        return $drawing;
    }

    /**
     * @throws ImagickException
     * @throws ImagickDrawException
     */
    function drawCommentMeta($x, $y, $text)
    {
        $drawMeta = new ImagickDraw();
        $drawMeta->setFont(__DIR__ . '/lib/fonts/noto_sans_regular.ttf');
        $drawMeta->setFillColor(new ImagickPixel('#929295'));
        $drawMeta->setFontSize(18);
        $this->image->annotateImage($drawMeta, $x, $y + 18, 0, $text);
        return [
            'height' => $this->image->queryFontMetrics($drawMeta, $text)['textHeight'],
            'width' => $this->image->queryFontMetrics($drawMeta, $text)['textWidth'],
        ];
    }

    /**
     * @throws ImagickException
     * @throws ImagickPixelException
     * @throws ImagickDrawException
     */
    function drawCommentAuthor($x, $y, $text, $is_op = false)
    {
        $drawAuthor = new ImagickDraw();
        $drawAuthor->setFont(__DIR__ . '/lib/fonts/noto_sans_bold.ttf');
        $drawAuthor->setFillColor(new ImagickPixel($is_op ? '#D04917' : '#3366B0'));
        $drawAuthor->setFontSize(18);
        $this->image->annotateImage($drawAuthor, $x, $y + 18, 0, $text);
        return [
            'height' => $this->image->queryFontMetrics($drawAuthor, $text)['textHeight'],
            'width' => $this->image->queryFontMetrics($drawAuthor, $text)['textWidth'],
        ];
    }

    function replaceProfanities($text)
    {
        $profanities = [
            'shit', 'shitting', 'cunt', 'fuck', 'fucking', 'nigger', 'fucker', 'motherfucker',
            'dickhead', 'asshole', 'bullshit', 'cock', 'dick', 'bloody',
            'retarded', 'retard', 'bitch', 'prick', 'piss', 'fag', 'ass', 'slut',
            'freaking', 'crap', 'frick'
        ];
        $words = explode(' ', $text);
        foreach ($words as $key => $word) {
            $wordRaw = $word;
            $word = preg_replace('/[^a-zA-Z]+/', '', $word);
            $word = strtolower($word);
            if (in_array($word, $profanities)) {
                $tmp = $wordRaw[0];
                for ($i = 1; $i < strlen($wordRaw) - 1; $i++) {
                    $tmp .= "*";
                }
                $tmp .= $wordRaw[strlen($wordRaw) - 1];
                $words[$key] = $tmp;
            }
        }
        return implode(' ', $words);
    }
}
