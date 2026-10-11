<?php
/*
Copyright (C) 2022 Zero Waste Paris

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <https://www.gnu.org/licenses/>.
*/

use CpChart\Data;
use CpChart\Image;
use Monolog\Logger;

require_once __DIR__ . '/utils.php';

class PlotCommand extends Command {

    private ?Logger $log;
    private array $parsedArgs = [];

    public function __construct() {
        global $log;
        $this->log = $log;
    }

    public function run() {
        $this->parseArgs();

        $startDate = $this->parsedArgs['start'];
        $endDate = $this->parsedArgs['end'];
		$rows = fetchEventsForExport();

        $this->log->info("Parsing " . count($rows) . " rows");
        $df = $this->parseRows($rows, $startDate, $endDate);
        if (empty($df)) {
            $this->log->warning("No data remaining after date filtering");
            return;
        }

        $this->log->info("Generating calplot (calendar heatmap)...");
        $this->generateCalplot($df);

        $this->log->info("Generating bene histogram (attendee duration distribution)...");
        $this->generateBeneHistogram($df);

        $this->log->info("Generating occ_duration charts (tag occurrences and duration)...");
        $this->generateOccDurationCharts($df);

        $this->log->info("All charts generated.");
    }

    public function getHelpMessage() {
        return "Generate charts (calplot, bene, occ_duration) from a CSV export or directly from the caldav server.\n"
            . "Usage: clitools plot [-s YYYY-MM-DD] [-e YYYY-MM-DD]\n"
            . "  -s, --start <date>      Start date filter (default: 1970-01-01)\n"
            . "  -e, --end <date>        End date filter (default: today)\n"
            . "Produces files: calplot.png, bene.png, occ_duration.png";
    }

    private function parseArgs(): void {
        global $argv;

        $args = array_slice($argv, 2);
        $startStr = '1970-01-01';
        $endStr = date('Y-m-d');

        $count = count($args);
        for ($i = 0; $i < $count; $i++) {
            switch ($args[$i]) {
                case '-s':
                case '--start':
                    $startStr = $args[++$i] ?? null;
                    break;
                case '-e':
                case '--end':
                    $endStr = $args[++$i] ?? null;
                    break;
            }
        }

        $this->parsedArgs['start'] = $this->parseDate($startStr);
        $this->parsedArgs['end'] = $this->parseDate($endStr);
    }

    private function parseDate(string $dateStr): DateTime {
        $d = DateTime::createFromFormat('Y-m-d', $dateStr);
        if ($d === false) {
            $this->log->error("Invalid date format: $dateStr (expected YYYY-MM-DD)");
            exit(1);
        }
        return $d;
    }

    private function parseRows(array $rows, DateTime $start, DateTime $end): array {
        $events = [];
        $startTs = $start->getTimestamp();
        $endTs = $end->getTimestamp();
        $endTs = strtotime('+1 day', $endTs); 

        foreach ($rows as $row) {
            $eventStart = (int)$row[0];
            $eventEnd = (int)$row[1];

            if ($eventStart < $startTs || $eventEnd > $endTs) {
                continue;
            }

            $attendeesStr = trim($row[2]);
            $attendees = $attendeesStr === '' ? [] : explode(',', $attendeesStr);

            $categoriesStr = trim($row[3]);
            $categories = $categoriesStr === '' ? [] : array_map('strtolower', explode(',', $categoriesStr));

            $durationHours = ($eventEnd - $eventStart) / 3600;

            $date = (new DateTime())->setTimestamp($eventStart)->format('Y-m-d');

            $events[] = [
                'start' => $eventStart,
                'end' => $eventEnd,
                'date' => $date,
                'attendees' => $attendees,
                'categories' => $categories,
                'duration' => $durationHours,
            ];
        }
        return $events;
    }

    private function generateCalplot(array $events): void {
        $dailyDuration = [];
        foreach ($events as $e) {
        if (!array_key_exists($e['date'], $dailyDuration)) {
          $dailyDuration[$e['date']] = 0;
        }
            $dailyDuration[$e['date']] += $e['duration'];
        }

        $maxDuration = max($dailyDuration);
        $this->renderCalplotPng($dailyDuration, $maxDuration);
    }

    private function renderCalplotPng(array $dailyDuration, float $maxDuration): void {
        $minDate = min(array_keys($dailyDuration));
        $maxDate = max(array_keys($dailyDuration));

        $minDt = new DateTime($minDate);
        $maxDt = new DateTime($maxDate);

        $startYear = (int)$minDt->format('Y');
        $endYear = (int)$maxDt->format('Y');
        $numYears = $endYear - $startYear + 1;

        $cellSize = 14;
        $cellGap = 3;
        $labelWidth = 40;
        $monthLabelHeight = 20;
        $dayLabelWidth = 30;
        $yearGap = 15;
        $yearTitleHeight = 20;

        $imgWidth = $labelWidth + $dayLabelWidth + 53 * ($cellSize + $cellGap);
        $yearBlockHeight = $monthLabelHeight + 7 * ($cellSize + $cellGap);
        $imgHeight = $yearTitleHeight * $numYears + $yearBlockHeight * $numYears + $yearGap * ($numYears - 1) + 25;

        $image = imagecreatetruecolor($imgWidth, $imgHeight);
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        imagefilledrectangle($image, 0, 0, $imgWidth, $imgHeight, $white);

        $fontPath = __DIR__ . '/../vendor/szymach/c-pchart/resources/fonts/GeosansLight.ttf';

        $yOffset = 0;
        $year = $startYear;
        while ($year <= $endYear) {
            imagettftext($image, 12, 0, 5, $yOffset + 15, $black, $fontPath, (string)$year);
            $yOffset += $yearTitleHeight;

            $days = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];
            for ($i = 0; $i < 7; $i++) {
                $y = $yOffset + $monthLabelHeight + $i * ($cellSize + $cellGap) + $cellSize;
                if ($days[$i] !== '') {
                    imagettftext($image, 8, 0, 5, $y, $black, $fontPath, $days[$i]);
                }
            }

            $months = ['Janv.', 'Fev.', 'Mars', 'Avril', 'Mai', 'Juin', 'Juil.', 'Août', 'Sept.', 'Oct.', 'Nov.', 'Dec.'];
            for ($m = 0; $m < 12; $m++) {
                $x = $labelWidth + $dayLabelWidth + (int)round($m * 4.4 * ($cellSize + $cellGap));
                if ($months[$m] !== '') {
                    imagettftext($image, 8, 0, $x, $yOffset + 15, $black, $fontPath, $months[$m]);
                }
            }

            $jan1 = new DateTime("$year-01-01");
            $startDayOfWeek = (int)$jan1->format('N') - 1;
            $yearEnd = new DateTime("$year-12-31");
            $daysInYear = (int)$yearEnd->format('z') + 1;

            for ($dayOfYear = 0; $dayOfYear < $daysInYear; $dayOfYear++) {
                $date = (clone $jan1)->modify("+$dayOfYear day");
                $dateStr = $date->format('Y-m-d');

                $dayOfWeek = ($startDayOfWeek + $dayOfYear) % 7;
                $weekOfYear = intdiv($startDayOfWeek + $dayOfYear, 7);

                $x = $labelWidth + $dayLabelWidth + $weekOfYear * ($cellSize + $cellGap);
                $y = $yOffset + $monthLabelHeight + $dayOfWeek * ($cellSize + $cellGap);

                if (isset($dailyDuration[$dateStr])) {
                    $intensity = $dailyDuration[$dateStr] / $maxDuration;
                    $color = $this->summerRColor($image, $intensity);
                } else {
                    $color = imagecolorallocate($image, 238, 238, 238);
                }
                imagefilledrectangle($image, $x, $y, $x + $cellSize, $y + $cellSize, $color);
                imagerectangle($image, $x, $y, $x + $cellSize, $y + $cellSize, $white);
            }

            $yOffset += $yearBlockHeight;
            if ($year < $endYear) {
                $yOffset += $yearGap;
            }
            $year++;
        }

        $legendX = $imgWidth - 130;
        $legendY = $imgHeight - 10;
        imagettftext($image, 8, 0, $legendX, $legendY - 3, $black, $fontPath, "0");
        for ($i = 0; $i < 5; $i++) {
            $intensity = $i / 4;
            $c = $this->summerRColor($image, $intensity);
            imagefilledrectangle($image, $legendX + 30 + $i * 12, $legendY - 12, $legendX + 30 + ($i + 1) * 12, $legendY - 2, $c);
        }
        imagettftext($image, 8, 0, $legendX + 95, $legendY - 3, $black, $fontPath, $maxDuration);

        imagepng($image, "calplot.png");
        $this->log->info("Saved calplot.png");
    }

    private function summerRColor($image, float $intensity): int {
        $r = (int)round(255 * (1 - $intensity));
        $g = 255;
        $b = (int)round(255 * (1 - $intensity));
        return imagecolorallocate($image, $r, $g, $b);
    }

    private function generateBeneHistogram(array $events): void {
        $attendeeHours = [];
        foreach ($events as $e) {
            foreach ($e['attendees'] as $a) {
                $a = trim($a);
                if ($a === '') continue;
                if (!isset($attendeeHours[$a])) {
                    $attendeeHours[$a] = 0;
                }
                $attendeeHours[$a] += $e['duration'];
            }
        }

        if (empty($attendeeHours)) {
            $this->log->warning("No attendee data for bene histogram");
            return;
        }

        $values = array_values($attendeeHours);
        $maxVal = max($values);
        $binSize = 10;
        $numBins = (int)ceil($maxVal / $binSize);
        if ($numBins < 1) $numBins = 1;

        $hist = array_fill(0, $numBins, 0);
        foreach ($values as $v) {
            $binIndex = min((int)floor($v / $binSize), $numBins - 1);
            $hist[$binIndex]++;
        }

        $centers = [];
        for ($i = 0; $i < $numBins; $i++) {
            $centers[] = ($i + 0.5) * $binSize;
        }

        $this->renderBenePng($hist, $centers);
    }

    private function renderBenePng(array $hist, array $centers): void {
        $width = 500;
        $height = 300;
        $margin = 60;

        $data = new Data();
        $data->addPoints($hist, 'Count');

        $maxCenter = max($centers);
        $binLabels = [];
        foreach ($centers as $c) {
            $binLabels[] = (string)round($c);
        }
        $data->addPoints($binLabels, 'Labels');
        $data->setAbscissa('Labels');
        $data->setAxisName(0, 'Nombre de bénévoles');

        $image = new Image($width, $height, $data);
        $image->setFontProperties(['FontName' => 'Forgotte.ttf', 'FontSize' => 8]);
        $image->drawText(
            $width / 2, 20,
            'Nombre bénévoles/salariées',
            ['FontSize' => 12, 'Align' => TEXT_ALIGN_BOTTOMMIDDLE]
        );
        $image->setGraphArea($margin, 40, $width - 20, $height - 50);
        $image->drawScale(['DrawSubTicks' => false]);
        $image->setShadow(true, ['X' => 1, 'Y' => 1, 'R' => 0, 'G' => 0, 'B' => 0, 'Alpha' => 10]);
        $image->setFontProperties(['FontName' => 'pf_arma_five.ttf', 'FontSize' => 6]);
        $image->drawBarChart([
            'DisplayValues' => true,
            'DisplayColor' => DISPLAY_AUTO,
            'Surrounding' => 60
        ]);
        $image->setShadow(false);
        $image->drawText($width / 2, $height - 10, "Nombre d'heures", ['Align' => TEXT_ALIGN_BOTTOMMIDDLE]);

        $image->render('bene.png');
        $this->log->info("Saved bene.png");
    }

    private function generateOccDurationCharts(array $events): void {
        $catOccurrences = [];
        $catDuration = [];

        foreach ($events as $e) {
            foreach ($e['categories'] as $cat) {
                $cat = trim($cat);
                if ($cat === '') continue;
                if (!isset($catOccurrences[$cat])) {
                    $catOccurrences[$cat] = 0;
                    $catDuration[$cat] = 0;
                }
                $catOccurrences[$cat]++;
                $catDuration[$cat] += $e['duration'];
            }
        }

        arsort($catOccurrences);
        arsort($catDuration);

        if (empty($catOccurrences)) {
            $this->log->warning("No category data for occ_duration charts");
            return;
        }

        $this->renderOccDurationPng($catOccurrences, $catDuration);
    }

    private function renderOccDurationPng(array $catOccurrences, array $catDuration): void {
        $width = 800;
        $halfHeight = 300;
        $margin = 60;
        $height = $halfHeight * 2;

        $cats = array_keys($catOccurrences);
        $occData = array_values($catOccurrences);
        $durData = array_values($catDuration);

        $data = new Data();
        $data->addPoints($occData, 'Occurrences');
        $data->addPoints($cats, 'Labels');
        $data->setAbscissa('Labels');

        $image = new Image($width, $halfHeight, $data);
        $image->setFontProperties(['FontName' => 'Forgotte.ttf', 'FontSize' => 8]);
        $image->drawText($width / 2, 15, "Nombre d'occurrences par tag", ['Align' => TEXT_ALIGN_BOTTOMMIDDLE, 'FontSize' => 12]);
        $image->setGraphArea($margin, 40, $width - 20, $halfHeight - 60);
        $image->drawScale(['LabelRotation' => 70, 'DrawSubTicks' => false]);
        $image->setShadow(true, ['X' => 1, 'Y' => 1, 'R' => 0, 'G' => 0, 'B' => 0, 'Alpha' => 10]);
        $image->setFontProperties(['FontName' => 'pf_arma_five.ttf', 'FontSize' => 6]);
        $image->drawBarChart([
            'DisplayValues' => true,
            'DisplayColor' => DISPLAY_AUTO,
            'Surrounding' => 60
        ]);
        $image->setShadow(false);

        $data2 = new Data();
        $data2->addPoints($durData, 'Duration');
        $data2->addPoints($cats, 'Labels');
        $data2->setAbscissa('Labels');

        $image2 = new Image($width, $halfHeight, $data2);
        $image2->setFontProperties(['FontName' => 'Forgotte.ttf', 'FontSize' => 8]);
        $image2->drawText($width / 2, 15, "Nombre d'heures par tag", ['Align' => TEXT_ALIGN_BOTTOMMIDDLE, 'FontSize' => 12]);
        $image2->setGraphArea($margin, 40, $width - 20, $halfHeight - 60);
        $image2->drawScale(['LabelRotation' => 70, 'DrawSubTicks' => false]);
        $image2->setShadow(true, ['X' => 1, 'Y' => 1, 'R' => 0, 'G' => 0, 'B' => 0, 'Alpha' => 10]);
        $image2->setFontProperties(['FontName' => 'pf_arma_five.ttf', 'FontSize' => 6]);
        $image2->drawBarChart([
            'DisplayValues' => true,
            'DisplayColor' => DISPLAY_AUTO,
            'Surrounding' => 60
        ]);
        $image2->setShadow(false);

        $combined = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($combined, 255, 255, 255);
        imagefilledrectangle($combined, 0, 0, $width, $height, $white);
        imagecopy($combined, $image->Picture, 0, 0, 0, 0, $width, $halfHeight);
        imagecopy($combined, $image2->Picture, 0, $halfHeight, 0, 0, $width, $halfHeight);

        imagepng($combined, 'occ_duration.png');
        $this->log->info("Saved occ_duration.png");
    }
}
