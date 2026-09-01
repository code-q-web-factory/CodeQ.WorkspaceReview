<?php

declare(strict_types=1);

namespace CodeQ\WorkspaceReview\Tests\Unit\Diff;

use CodeQ\WorkspaceReview\Diff\RichTextDiffer;
use Neos\Flow\Tests\UnitTestCase;

class RichTextDifferTest extends UnitTestCase
{
    /**
     * @test
     * @dataProvider markupChanges
     */
    public function compareReportsMarkupChanges(string $original, string $changed, array $expectedFindings): void
    {
        $differ = new RichTextDiffer();

        self::assertSame($expectedFindings, $differ->compare($original, $changed));
    }

    public static function markupChanges(): array
    {
        return [
            'link target changed while the label stays' => [
                '<a href="http://parlament.neos.eu/abgeordnete"><strong>parlament.neos.eu/abgeordnete</strong></a>',
                '<a href="https://parlament.neos.eu/abgeordnete"><strong>parlament.neos.eu/abgeordnete</strong></a>',
                [
                    [
                        'kind' => 'linkTarget',
                        'linkText' => 'parlament.neos.eu/abgeordnete',
                        'original' => 'http://parlament.neos.eu/abgeordnete',
                        'changed' => 'https://parlament.neos.eu/abgeordnete',
                    ],
                ],
            ],
            'title repeating the changed target is not reported twice' => [
                '<a href="http://vorwahl.neos.eu" target="_blank" title="http://vorwahl.neos.eu">vorwahl.neos.eu</a>',
                '<a href="https://vorwahl.neos.eu" target="_blank" title="https://vorwahl.neos.eu">vorwahl.neos.eu</a>',
                [
                    [
                        'kind' => 'linkTarget',
                        'linkText' => 'vorwahl.neos.eu',
                        'original' => 'http://vorwahl.neos.eu',
                        'changed' => 'https://vorwahl.neos.eu',
                    ],
                ],
            ],
            'link now opens in a new window' => [
                '<p>Mehr auf <a href="https://neos.eu">neos.eu</a></p>',
                '<p>Mehr auf <a href="https://neos.eu" target="_blank">neos.eu</a></p>',
                [
                    [
                        'kind' => 'linkAttribute',
                        'linkText' => 'neos.eu',
                        'attribute' => 'target',
                        'original' => null,
                        'changed' => '_blank',
                    ],
                ],
            ],
            'link title changed' => [
                '<p>Mehr auf <a href="https://neos.eu" title="Alter Titel">neos.eu</a></p>',
                '<p>Mehr auf <a href="https://neos.eu" title="Neuer Titel">neos.eu</a></p>',
                [
                    [
                        'kind' => 'linkAttribute',
                        'linkText' => 'neos.eu',
                        'attribute' => 'title',
                        'original' => 'Alter Titel',
                        'changed' => 'Neuer Titel',
                    ],
                ],
            ],
            'window and title changed at once are reported one after the other' => [
                '<p>Mehr auf <a href="https://neos.eu" title="Alter Titel">neos.eu</a></p>',
                '<p>Mehr auf <a href="https://neos.eu" target="_blank" title="Neuer Titel">neos.eu</a></p>',
                [
                    [
                        'kind' => 'linkAttribute',
                        'linkText' => 'neos.eu',
                        'attribute' => 'target',
                        'original' => null,
                        'changed' => '_blank',
                    ],
                    [
                        'kind' => 'linkAttribute',
                        'linkText' => 'neos.eu',
                        'attribute' => 'title',
                        'original' => 'Alter Titel',
                        'changed' => 'Neuer Titel',
                    ],
                ],
            ],
            'link removed but the words stay' => [
                '<p>Mehr auf <a href="https://neos.eu">neos.eu</a> lesen</p>',
                '<p>Mehr auf neos.eu lesen</p>',
                [
                    ['kind' => 'linkRemoved', 'linkText' => 'neos.eu', 'href' => 'https://neos.eu'],
                ],
            ],
            'existing words are linked now' => [
                '<p>Mehr auf neos.eu lesen</p>',
                '<p>Mehr auf <a href="https://neos.eu">neos.eu</a> lesen</p>',
                [
                    ['kind' => 'linkAdded', 'linkText' => 'neos.eu', 'href' => 'https://neos.eu'],
                ],
            ],
            'label rewritten together with the target belongs to the text diff' => [
                '<p><a href="https://neos.eu/alt">Alte Seite</a></p>',
                '<p><a href="https://neos.eu/neu">Neue Seite</a></p>',
                [],
            ],
            'umlauts and entities survive extraction' => [
                '<p><a href="http://neos.eu/ueber-uns">Über uns &amp; Grüße</a></p>',
                '<p><a href="https://neos.eu/ueber-uns">Über uns &amp; Grüße</a></p>',
                [
                    [
                        'kind' => 'linkTarget',
                        'linkText' => 'Über uns & Grüße',
                        'original' => 'http://neos.eu/ueber-uns',
                        'changed' => 'https://neos.eu/ueber-uns',
                    ],
                ],
            ],
            'single word became bold' => [
                '<p>Hallo schöne Welt</p>',
                '<p>Hallo <strong>schöne</strong> Welt</p>',
                [
                    [
                        'kind' => 'formatting',
                        'text' => 'schöne',
                        'originalMarks' => [],
                        'changedMarks' => ['bold'],
                    ],
                ],
            ],
            'neighbouring words with the same formatting change are grouped' => [
                '<p>Das ist ein wichtiger Hinweis</p>',
                '<p>Das ist <strong>ein wichtiger</strong> Hinweis</p>',
                [
                    [
                        'kind' => 'formatting',
                        'text' => 'ein wichtiger',
                        'originalMarks' => [],
                        'changedMarks' => ['bold'],
                    ],
                ],
            ],
            'neighbouring words with different formatting changes stay apart' => [
                '<p>Das <em>ist</em> ein Hinweis</p>',
                '<p>Das <strong>ist</strong> <u>ein</u> Hinweis</p>',
                [
                    [
                        'kind' => 'formatting',
                        'text' => 'ist',
                        'originalMarks' => ['italic'],
                        'changedMarks' => ['bold'],
                    ],
                    [
                        'kind' => 'formatting',
                        'text' => 'ein',
                        'originalMarks' => [],
                        'changedMarks' => ['underline'],
                    ],
                ],
            ],
            'a block boundary keeps the words of both blocks apart' => [
                '<p>Erster Absatz</p><p>Zweiter Absatz</p>',
                '<p>Erster Absatz</p><p><strong>Zweiter</strong> Absatz</p>',
                [
                    [
                        'kind' => 'formatting',
                        'text' => 'Zweiter',
                        'originalMarks' => [],
                        'changedMarks' => ['bold'],
                    ],
                ],
            ],
            'paragraph became a heading' => [
                '<p>Überschrift des Absatzes</p>',
                '<h2>Überschrift des Absatzes</h2>',
                [
                    [
                        'kind' => 'formatting',
                        'text' => 'Überschrift des Absatzes',
                        'originalMarks' => [],
                        'changedMarks' => ['heading2'],
                    ],
                ],
            ],
            'nested formatting is reported as a sorted mark list' => [
                '<p>Ein <em>hervorgehobenes</em> Wort</p>',
                '<p>Ein <strong><em>hervorgehobenes</em></strong> Wort</p>',
                [
                    [
                        'kind' => 'formatting',
                        'text' => 'hervorgehobenes',
                        'originalMarks' => ['italic'],
                        'changedMarks' => ['bold', 'italic'],
                    ],
                ],
            ],
            'long reformatted passages are shortened in the middle' => [
                '<p>eins zwei drei vier fuenf sechs sieben acht neun zehn elf zwoelf dreizehn</p>',
                '<p><strong>eins zwei drei vier fuenf sechs sieben acht neun zehn elf zwoelf dreizehn</strong></p>',
                [
                    [
                        'kind' => 'formatting',
                        'text' => 'eins zwei drei vier fuenf sechs … acht neun zehn elf zwoelf dreizehn',
                        'originalMarks' => [],
                        'changedMarks' => ['bold'],
                    ],
                ],
            ],
            'unclosed tags still reveal the changed target' => [
                '<p>Ein <a href="http://neos.eu"><strong>Link</p>',
                '<p>Ein <a href="https://neos.eu"><strong>Link</strong></a></p>',
                [
                    [
                        'kind' => 'linkTarget',
                        'linkText' => 'Link',
                        'original' => 'http://neos.eu',
                        'changed' => 'https://neos.eu',
                    ],
                ],
            ],
        ];
    }

    /**
     * @test
     * @dataProvider invisibleDifferences
     */
    public function compareIgnoresDifferencesWithoutMarkupMeaning(string $original, string $changed): void
    {
        $differ = new RichTextDiffer();

        self::assertSame([], $differ->compare($original, $changed));
    }

    public static function invisibleDifferences(): array
    {
        return [
            'identical markup' => ['<p>Hallo Welt</p>', '<p>Hallo Welt</p>'],
            'identical plain text' => ['Hallo Welt', 'Hallo Welt'],
            'plain text edited' => ['Hallo Welt', 'Hallo schöne Welt'],
            'encoded and literal ampersand' => ['<p>Grüße &amp; Küsse</p>', '<p>Grüße & Küsse</p>'],
            'non-breaking space and space' => ['<p>Hallo&nbsp;Welt</p>', '<p>Hallo Welt</p>'],
            'collapsed whitespace' => ["<p>Hallo   \n Welt</p>", '<p>Hallo Welt</p>'],
            'reordered attributes and markup whitespace' => [
                '<a href="https://neos.eu" title="Neos">neos.eu</a>',
                '<a  title="Neos"   href="https://neos.eu" >neos.eu</a>',
            ],
            'empty target attribute equals a missing one' => [
                '<a href="https://neos.eu" target="">neos.eu</a>',
                '<a href="https://neos.eu">neos.eu</a>',
            ],
            'the target keyword compares case-insensitively' => [
                '<a href="https://neos.eu" target="_BLANK">neos.eu</a>',
                '<a href="https://neos.eu" target="_blank">neos.eu</a>',
            ],
            'anchor text extended over words that were there before' => [
                '<p>Bitte <a href="/kontakt">hier</a> klicken</p>',
                '<p>Bitte <a href="/kontakt">hier klicken</a></p>',
            ],
            'anchor text extended by a new word' => [
                '<p>Bitte <a href="/kontakt">hier</a> melden</p>',
                '<p>Bitte <a href="/kontakt">hier klicken</a> melden</p>',
            ],
            'anchor text and target changed together' => [
                '<p>Bitte <a href="/kontakt">hier</a> melden</p>',
                '<p>Bitte <a href="/impressum">hier klicken</a> melden</p>',
            ],
            'a second link with the same label is not paired by position' => [
                '<p>Klicken Sie <a href="/2">hier</a></p>',
                '<p>Klicken Sie <a href="/1">hier</a> oder <a href="/2">hier</a></p>',
            ],
            'an anchor label that is only a substring of the new text' => [
                '<p>Mehr <a href="/info">Info</a> hier</p>',
                '<p>Mehr Information hier</p>',
            ],
            'a link inside an entirely new paragraph' => [
                '<p>Unser Team ist für Sie da</p>',
                '<p>Unser Team ist für Sie da</p><p>Das <a href="/team">Team</a> im Detail</p>',
            ],
            'unclosed tag recovers to the same markup' => [
                '<p>Hallo <strong>Welt</p>',
                '<p>Hallo <strong>Welt</strong></p>',
            ],
            'severely broken markup' => ['<<<>>> kaputt <p offen <a href=', '<p>Text</p>'],
            'link removed together with its words' => [
                '<p>Mehr auf <a href="https://neos.eu">neos.eu</a> lesen</p>',
                '<p>Mehr lesen</p>',
            ],
            'original side empty' => ['', '<p>Hallo Welt</p>'],
            'changed side empty' => ['<p>Hallo Welt</p>', ''],
            'both sides empty' => ['', ''],
            'link without a label' => ['<p><a href="https://neos.eu"></a>Text</p>', '<p><a href="https://neos.io"></a>Text</p>'],
        ];
    }
}
