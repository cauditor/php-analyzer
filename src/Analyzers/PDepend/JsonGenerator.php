<?php

namespace Cauditor\Analyzers\PDepend;

use PDepend\Metrics\Analyzer as PDependAnalyzer;
use PDepend\Metrics\AnalyzerNodeAware;
use PDepend\Metrics\AnalyzerProjectAware;
use PDepend\Report\CodeAwareGenerator;
use PDepend\Report\FileAwareGenerator;
use PDepend\Source\AST\AbstractASTClassOrInterface;
use PDepend\Source\AST\ASTArtifact;
use PDepend\Source\AST\ASTArtifactList;
use PDepend\Source\AST\ASTClass;
use PDepend\Source\AST\ASTFunction;
use PDepend\Source\AST\ASTInterface;
use PDepend\Source\AST\ASTMethod;
use PDepend\Source\AST\ASTNamespace;
use PDepend\Source\AST\ASTTrait;
use PDepend\Source\ASTVisitor\AbstractASTVisitor;

class JsonGenerator extends AbstractASTVisitor implements CodeAwareGenerator, FileAwareGenerator
{
    /**
     * @var string
     */
    protected $logFile;

    /**
     * @var AnalyzerProjectAware[]
     */
    protected $projectAnalyzers = array();

    /**
     * @var AnalyzerNodeAware[]
     */
    protected $nodeAnalyzers = array();

    /**
     * @var ASTArtifactList
     */
    protected $artifacts;

    /**
     * @var array
     */
    protected $data = array();

    /**
     * Project-wide totals for all node analyzers.
     *
     * @var array
     */
    protected $projectMetrics = array();

    /**
     * List of metrics to include and what to map them to.
     *
     * @var string[]
     */
    protected $metrics = array(
        'eloc' => 'loc',
        'noc' => 'noc',
        'nom' => 'nom',
        'ca' => 'ca',
        'ce' => 'ce',
        'i' => 'i',
        'dit' => 'dit',
        'ccn2' => 'ccn',
        'npath' => 'npath',
        'he' => 'he',
        'hi' => 'hi',
        'mi' => 'mi',
    );

    /**
     * {@inheritdoc}
     */
    public function log(PDependAnalyzer $analyzer)
    {
        $accept = true;

        if ($analyzer instanceof AnalyzerProjectAware) {
            $this->projectAnalyzers[] = $analyzer;
            // don't return just yet, it may also be a node analyzer ;)
            $accept = true;
        }

        if ($analyzer instanceof AnalyzerNodeAware) {
            $this->nodeAnalyzers[] = $analyzer;
            $accept = true;
        }

        return $accept;
    }

    /**
     * {@inheritdoc}
     */
    public function close()
    {
        foreach ($this->artifacts as $artifact) {
            $artifact->accept($this);
        }

        $data = $this->getProjectMetrics() + $this->projectMetrics;
        $data = $this->addInstability($data);
        $data += array('children' => $this->data);

        $json = json_encode($data);
        file_put_contents($this->logFile, $json);
    }

    /**
     * {@inheritdoc}
     */
    public function getAcceptedAnalyzers()
    {
        return array(
            'pdepend.analyzer.cyclomatic_complexity',
            'pdepend.analyzer.node_loc',
            'pdepend.analyzer.npath_complexity',
            'pdepend.analyzer.inheritance',
            'pdepend.analyzer.node_count',
            'pdepend.analyzer.coupling',
            'pdepend.analyzer.halstead',
            'pdepend.analyzer.maintainability',
        );
    }

    /**
     * {@inheritdoc}
     */
    public function setLogFile($logFile)
    {
        $this->logFile = $logFile;
    }

    /**
     * {@inheritdoc}
     */
    public function setArtifacts(ASTArtifactList $artifacts)
    {
        $this->artifacts = $artifacts;
    }

    /**
     * {@inheritdoc}
     */
    public function visitNamespace(ASTNamespace $node)
    {
        $data = array();
        $data['name'] = $node->getName();
        $data += $this->getNodeMetrics($node);
        $data['children'] = array();

        $this->data[] = $data;

        // process classes, traits, interfaces in this namespace
        foreach ($node->getTypes() as $type) {
            $type->accept($this);
        }

        // process functions in this namespace
        foreach ($node->getFunctions() as $function) {
            $function->accept($this);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function visitClass(ASTClass $node)
    {
        $this->visitType($node);
    }

    /**
     * {@inheritdoc}
     */
    public function visitTrait(ASTTrait $node)
    {
        $this->visitType($node);
    }

    /**
     * {@inheritdoc}
     */
    public function visitInterface(ASTInterface $node)
    {
        // skip interfaces, they have no code...
    }

    /**
     * @param AbstractASTClassOrInterface $node
     */
    protected function visitType(AbstractASTClassOrInterface $node)
    {
        $data = array();
        $data['name'] = $node->getName();
        $data += $this->getNodeMetrics($node);
        $data['children'] = array();

        $namespace = count($this->data) - 1;
        $this->data[$namespace]['children'][] = $data;

        // process methods in this class/trait/interface
        foreach ($node->getMethods() as $method) {
            $method->accept($this);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function visitFunction(ASTFunction $node)
    {
        $data = array();
        $data['name'] = $node->getName();
        $data += $this->getNodeMetrics($node);

        $namespace = count($this->data) - 1;
        $this->data[$namespace]['children'][] = $data;
    }

    /**
     * {@inheritdoc}
     */
    public function visitMethod(ASTMethod $node)
    {
        $data = array();
        $data['name'] = $node->getName();
        $data += $this->getNodeMetrics($node);

        $namespace = count($this->data) - 1;
        $class = count($this->data[$namespace]['children']) - 1;
        $this->data[$namespace]['children'][$class]['children'][] = $data;
    }

    /**
     * @return int[]
     */
    protected function getProjectMetrics()
    {
        $metrics = array();
        foreach ($this->projectAnalyzers as $analyzer) {
            $metrics += $analyzer->getProjectMetrics();
        }

        return $this->normalizeMetrics($metrics);
    }

    /**
     * @param ASTArtifact $node
     *
     * @return int[]
     */
    protected function getNodeMetrics(ASTArtifact $node)
    {
        $metrics = array();
        foreach ($this->nodeAnalyzers as $analyzer) {
            $metrics += $analyzer->getNodeMetrics($node);
        }

        $metrics = $this->normalizeMetrics($metrics);

        // add node metric to project-wide totals
        foreach ($metrics as $metric => $value) {
            if (!isset($this->projectMetrics[$metric])) {
                $this->projectMetrics[$metric] = 0;
            }

            $this->projectMetrics[$metric] += $value;
        }

        return $metrics;
    }

    /**
     * @param array $metrics
     *
     * @return array
     */
    protected function normalizeMetrics(array $metrics)
    {
        $result = array();

        foreach ($this->metrics as $metric => $replacement) {
            if (isset($metrics[$metric])) {
                $result[$replacement] = $metrics[$metric];
            }
        }

        foreach ($result as $metric => $value) {
            if (is_float($value)) {
                $result[$metric] = number_format($value, 2, '.', '');
            }

            // cast to float so the JSON value is numeric (int will be
            // encapsulated in quotes...)
            $result[$metric] = (float) $result[$metric];
        }

        $result = $this->addInstability($result);

        return $result;
    }

    /**
     * pdepend.analyzer.dependency also calculates instability, but not on a
     * per-class level, and defauling to 0 instead of 1.
     *
     * @param array $metrics
     *
     * @return array
     */
    protected function addInstability(array $metrics)
    {
        if (isset($metrics['ca']) && isset($metrics['ce'])) {
            $metrics['i'] = (float) number_format($metrics['ce'] / (($metrics['ce'] + $metrics['ca']) ?: 1), 2);
        }

        return $metrics;
    }
}
