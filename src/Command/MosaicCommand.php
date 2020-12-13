<?php


namespace App\Command;


use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Process\Process;

class MosaicCommand extends Command
{
    private const RESULTS_PATH = __DIR__.'/../../results';

    protected function configure()
    {
        $this->setName('mosaic')
            ->addArgument('w', InputArgument::REQUIRED)
            ->addArgument('h', InputArgument::REQUIRED)
            ->addArgument('source', InputArgument::OPTIONAL, '', 'latest');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $w = $input->getArgument('w');
        $h = $input->getArgument('h');
        $time = time();
        $source = $input->getArgument('source');
        $resultName = "mosaic_{$w}x{$h}_{$time}";
        $rowName = uniqid('mosaic_row', true);

        $resultsPath = self::RESULTS_PATH;
        $uid = getmyuid();
        $gid = getmygid();

        $temp = sys_get_temp_dir();

        if ('latest' === $source) {
            $source = $this->getLatestResult();

            if (null === $source) {
                $output->writeln('<error>No latest result found.</error>');

                return 1;
            }

            $output->writeln('Working on '.$source);
        }

        $rowCommand = 'docker run --rm --gpus all '.
            "-v $resultsPath:/results ".
            "-v $temp:/tmp ".
            'first-order-model '.
            'ffmpeg ';

        for ($i = 0; $i < $w; $i++) {
            $rowCommand .= "-i /results/$source ";
        }

        $rowCommand .= "-filter_complex hstack=inputs=$w /tmp/$rowName.mp4";

        $mosaicCommand = 'docker run --rm --gpus all '.
            "-v $resultsPath:/results ".
            "-v $temp:/tmp ".
            'first-order-model '.
            'ffmpeg ';

        for ($i = 0; $i < $h; $i++) {
            $mosaicCommand .= "-i /tmp/$rowName.mp4 ";
        }

        $mosaicCommand .= "-filter_complex vstack=inputs=$h /results/$resultName.mp4";

        $permissionFixerCommand = 'docker run --rm --gpus all '.
            "-v $resultsPath:/results ".
            'first-order-model '.
            "chown $uid:$gid /results/$resultName.mp4";

        $rowGenerator = new Process(explode(' ', $rowCommand), null, null, null, null);
        $mosaicGenerator = new Process(explode(' ', $mosaicCommand), null, null, null, null);
        $permissionFixer = new Process(explode(' ', $permissionFixerCommand), null, null, null, null);

        $output->writeln('Generating row...');
        $rowGenerator->start();
        $rowGenerator->wait();

        if (!$rowGenerator->isSuccessful()) {
            $output->writeln('<error>Row generation failed.</error>');

            $output->write($rowGenerator->getErrorOutput());

            return 1;
        }

        $output->writeln('Generating mosaic...');
        $mosaicGenerator->start();
        $mosaicGenerator->wait();

        if (!$mosaicGenerator->isSuccessful()) {
            $output->writeln('<error>Mosaic generation failed.</error>');

            $output->write($mosaicGenerator->getErrorOutput());

            return 1;
        }

        $output->writeln('Fixing fs permissions...');
        $permissionFixer->start();
        $permissionFixer->wait();

        return 0;
    }

    private function getLatestResult(): ?string
    {
        $finder = Finder::create()
            ->files()
            ->in(self::RESULTS_PATH)
            ->name('memeized_at_*.mp4')
            ->sortByModifiedTime()
            ->reverseSorting();

        /** @var SplFileInfo $file */
        $file = $finder->getIterator()->current();

        return $file ? $file->getBasename() : null;
    }
}
