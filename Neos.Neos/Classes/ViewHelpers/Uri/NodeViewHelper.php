<?php
namespace Neos\Neos\ViewHelpers\Uri;

/*
 * This file is part of the Neos.Neos package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\ContentRepository\Domain\Projection\Content\ContentSubgraphInterface;
use Neos\ContentRepository\Domain\Context\NodeAggregate\NodeAggregateIdentifier;
use Neos\ContentRepository\Domain\Projection\Content\NodeInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Routing\UriBuilder;
use Neos\Neos\Domain\Context\Content\NodeAddress;
use Neos\Neos\Domain\Context\Content\NodeAddressFactory;
use Neos\Neos\Domain\Service\NodeShortcutResolver;
use Neos\FluidAdaptor\Core\ViewHelper\AbstractViewHelper;
use Neos\Fusion\ViewHelpers\FusionContextTrait;

/**
 * A view helper for creating URIs pointing to nodes.
 *
 * The target node can be provided as string or as a Node object; if not specified
 * at all, the generated URI will refer to the current document node inside the Fusion context.
 *
 * When specifying the ``node`` argument as string, the following conventions apply:
 *
 * *``node`` starts with ``/``:*
 * The given path is an absolute node path and is treated as such.
 * Example: ``/sites/acmecom/home/about/us``
 *
 * *``node`` does not start with ``/``:*
 * The given path is treated as a path relative to the current node.
 * Examples: given that the current node is ``/sites/acmecom/products/``,
 * ``stapler`` results in ``/sites/acmecom/products/stapler``,
 * ``../about`` results in ``/sites/acmecom/about/``,
 * ``./neos/info`` results in ``/sites/acmecom/products/neos/info``.
 *
 * *``node`` starts with a tilde character (``~``):*
 * The given path is treated as a path relative to the current site node.
 * Example: given that the current node is ``/sites/acmecom/products/``,
 * ``~/about/us`` results in ``/sites/acmecom/about/us``,
 * ``~`` results in ``/sites/acmecom``.
 *
 * = Examples =
 *
 * <code title="Default">
 * <neos:uri.node />
 * </code>
 * <output>
 * homepage/about.html
 * (depending on current workspace, current node, format etc.)
 * </output>
 *
 * <code title="Generating an absolute URI">
 * <neos:uri.node absolute="{true"} />
 * </code>
 * <output>
 * http://www.example.org/homepage/about.html
 * (depending on current workspace, current node, format, host etc.)
 * </output>
 *
 * <code title="Target node given as absolute node path">
 * <neos:uri.node node="/sites/acmecom/about/us" />
 * </code>
 * <output>
 * about/us.html
 * (depending on current workspace, current node, format etc.)
 * </output>
 *
 * <code title="Target node given as relative node path">
 * <neos:uri.node node="~/about/us" />
 * </code>
 * <output>
 * about/us.html
 * (depending on current workspace, current node, format etc.)
 * </output>
 *
 * <code title="Target node given as node://-uri">
 * <neos:uri.node node="node://30e893c1-caef-0ca5-b53d-e5699bb8e506" />
 * </code>
 * <output>
 * about/us.html
 * (depending on current workspace, current node, format etc.)
 * </output>
 * @api
 */
class NodeViewHelper extends AbstractViewHelper
{
    use FusionContextTrait;

    /**
     * @Flow\Inject
     * @var NodeShortcutResolver
     */
    protected $nodeShortcutResolver;


    /**
     * @Flow\Inject
     * @var NodeAddressFactory
     */
    protected $nodeAddressFactory;

    /**
     * Renders the URI.
     *
     * @param mixed $node A node object, a string node path (absolute or relative), a string node://-uri or NULL
     * @param string $format Format to use for the URL, for example "html" or "json"
     * @param boolean $absolute If set, an absolute URI is rendered
     * @param array $arguments Additional arguments to be passed to the UriBuilder (for example pagination parameters)
     * @param string $section
     * @param boolean $addQueryString If set, the current query parameters will be kept in the URI
     * @param array $argumentsToBeExcludedFromQueryString arguments to be removed from the URI. Only active if $addQueryString = TRUE
     * @param boolean $resolveShortcuts INTERNAL Parameter - if FALSE, shortcuts are not redirected to their target. Only needed on rare backend occasions when we want to link to the shortcut itself.
     * @param ContentSubgraphInterface|null $subgraph The explicit override of the subgraph retrieved from the fusion context, e.g. for dimension menus
     * @return string The rendered URI or NULL if no URI could be resolved for the given node
     * @throws \Neos\Flow\Mvc\Routing\Exception\MissingActionNameException
     */
    public function render(
        $node = null,
        $format = null,
        $absolute = false,
        array $arguments = array(),
        $section = '',
        $addQueryString = false,
        array $argumentsToBeExcludedFromQueryString = array(),
        $resolveShortcuts = true,
        ContentSubgraphInterface $subgraph = null
    ) {
        $uri = null;
        $nodeAddress = null;


        if ($node instanceof NodeInterface) {
            // the latter case is only relevant in extremely rare occasions in the Neos Backend, when we want to generate
            // a link towards the *shortcut itself*, and not to its target.
            // TODO: fix shortcuts!
            //$resolvedNode = $resolveShortcuts ? $resolvedNode = $this->nodeShortcutResolver->resolveShortcutTarget($node) : $node;
            $resolvedNode = $node;
            if ($resolvedNode instanceof NodeInterface) {
                $nodeAddress = $this->nodeAddressFactory->createFromNode($node);
            } else {
                $uri = $resolvedNode;
            }
        } elseif ($node === '~') {
            /* @var $documentNode \Neos\ContentRepository\Domain\Projection\Content\NodeInterface */
            $documentNode = $this->getContextVariable('documentNode');
            $nodeAddress = $this->nodeAddressFactory->createFromNode($documentNode);
            $siteNode = $this->nodeAddressFactory->findSiteNodeForNodeAddress($nodeAddress);
            $nodeAddress = $nodeAddress->withNodeAggregateIdentifier($siteNode->getNodeAggregateIdentifier());
        } elseif (is_string($node) && substr($node, 0, 7) === 'node://') {
            /* @var $documentNode \Neos\ContentRepository\Domain\Projection\Content\NodeInterface */
            $documentNode = $this->getContextVariable('documentNode');
            $nodeAddress = $this->nodeAddressFactory->createFromNode($documentNode);
            $nodeAddress = $nodeAddress->withNodeAggregateIdentifier(new NodeAggregateIdentifier(\mb_substr($node, 7)));
        } else {
            // @todo add path support
            return '';
        }

        if (!$uri) {
            if ($subgraph) {
                $nodeAddress = $nodeAddress->withDimensionSpacePoint($subgraph->getDimensionSpacePoint());
            }

            $uriBuilder = new UriBuilder();
            $uriBuilder->setRequest($this->controllerContext->getRequest());
            $uriBuilder->setFormat($format)
                ->setCreateAbsoluteUri($absolute)
                ->setArguments($arguments)
                ->setSection($section)
                ->setAddQueryString($addQueryString)
                ->setArgumentsToBeExcludedFromQueryString($argumentsToBeExcludedFromQueryString);

            $uri = $uriBuilder->uriFor(
                'show',
                [
                    'node' => $nodeAddress
                ],
                'Frontend\Node',
                'Neos.Neos'
            );
        }

        return $uri;
    }
}
