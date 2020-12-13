<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class MemeizeCommand extends Command
{
    private const DRIVERS_PATH = __DIR__.'/../../drivers';
    private const TRAININGS_PATH = __DIR__.'/../../trainings';
    private const RESULTS_PATH = __DIR__.'/../../results';

    protected function configure()
    {
        $this->setName('memeize')
            ->addArgument('driver', InputArgument::REQUIRED)
            ->addArgument('photo', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $resultName = 'memeized_at_'.time();
        $driver = $input->getArgument('driver');
        $photoPath = $input->getArgument('photo');

        $this->memeize($resultName, $driver, $photoPath, $output);

        return 0;
    }

    private function memeize(string $resultName, string $driver, string $photoPath, OutputInterface $output): void
    {
        $driversPath = self::DRIVERS_PATH;
        $trainingsPath = self::TRAININGS_PATH;
        $resultsPath = self::RESULTS_PATH;
        $uid = getmyuid();
        $gid = getmygid();

        $temp = sys_get_temp_dir();

        $tempName = uniqid('meme_picture', true);
        $soundlessName = uniqid('soundless', true);

        copy($photoPath, $temp.'/'.$tempName);

        $transformationCommand = 'docker run --rm --gpus all '.
            "-v $driversPath:/drivers ".
            "-v $trainingsPath:/trainings ".
            "-v $temp:/tmp ".
            'first-order-model '.
            'python3 demo.py --config config/vox-256.yaml '.
            "--driving_video /drivers/$driver.mp4 ".
            "--source_image /tmp/$tempName ".
            '--checkpoint /trainings/vox-cpk.pth.tar '.
            "--result_video /tmp/$soundlessName.mp4 ".
            '--relative '.
            '--adapt_scale';

        $soundMergeCommand = 'docker run --rm --gpus all '.
            "-v $driversPath:/drivers ".
            "-v $resultsPath:/results ".
            "-v $temp:/tmp ".
            'first-order-model '.
            'ffmpeg '.
            "-i /tmp/$soundlessName.mp4 -i /drivers/$driver.mp4 ".
            "-map 0:v -map 1:a -codec copy -shortest -y /results/$resultName.mp4";

        $permissionFixerCommand = 'docker run --rm --gpus all '.
            "-v $resultsPath:/results ".
            'first-order-model '.
            "chown $uid:$gid /results/$resultName.mp4";

        $transformer = new Process(explode(' ', $transformationCommand), null, null, null, null);

        $output->writeln('Memeizing video...');
        $transformer->start();

        foreach ($transformer as $type => $data) {
            if (false === stripos($data, 'warn')) {
                $output->write($data);
            }
        }

        $output->writeln('');

        $soundMerger = new Process(explode(' ', $soundMergeCommand), null, null, null, null);

        $output->writeln('Adding sound to the video...');
        $soundMerger->start();
        $soundMerger->wait();

        $permissionFixer = new Process(explode(' ', $permissionFixerCommand), null, null, null, null);

        $output->writeln('Fixing fs permissions...');
        $permissionFixer->start();
        $permissionFixer->wait();

        $output->writeln('Done.');
    }
}
