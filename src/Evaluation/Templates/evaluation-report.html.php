<h1>Evaluation Report</h1>
<div class="summary">
    <h2>Agent: <?= htmlspecialchars($agentName) ?></h2>
    <p><strong>Dataset Size:</strong> <?= $datasetSize ?> items</p>
</div>

<h2>Metrics Summary</h2>
<?php foreach ($metrics as $name => $data) { ?>
<div class="metric">
    <h3><?= htmlspecialchars($name) ?></h3>
    <div class="score"><?= round($data['average'] * 100, 1) ?>%</div>
    <p><?= htmlspecialchars($data['description']) ?></p>
    <small>Min: <?= $data['min'] ?> | Max: <?= $data['max'] ?> | Avg: <?= $data['average'] ?></small>
</div>
<?php } ?>

<h2>Detailed Results</h2>
<?php foreach ($results as $i => $result) { ?>
<?php $num = $i + 1; ?>
<div class="result">
    <div class="input"><strong>Input #<?= $num ?>:</strong> <?= htmlspecialchars($result['input']) ?></div>
    <div class="output"><strong>Output:</strong> <?= htmlspecialchars($result['output']) ?></div>
    <?php if (isset($result['expected'])) { ?>
    <div><strong>Expected:</strong> <?= htmlspecialchars($result['expected']) ?></div>
    <?php } ?>
    <div class="metrics">
        <?php foreach ($result['metrics'] as $metricName => $score) { ?>
        <span class="metric-badge"><?= htmlspecialchars($metricName) ?>: <?= round($score * 100, 1) ?>%</span>
        <?php } ?>
    </div>
</div>
<?php } ?>
