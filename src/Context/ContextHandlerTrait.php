<?php

/**
 * @file
 * Contains \Drupal\rules\Context\ContextHandlerTrait.
 */

namespace Drupal\rules\Context;

use Drupal\Component\Plugin\Exception\ContextException;
use Drupal\Core\Plugin\ContextAwarePluginInterface as CoreContextAwarePluginInterface;
use Drupal\rules\Engine\ExecutionMetadataStateInterface;
use Drupal\rules\Engine\ExecutionStateInterface;
use Drupal\rules\Exception\RulesEvaluationException;

/**
 * Provides methods for handling context based on the plugin configuration.
 *
 * The trait requires the plugin to use configuration as defined by the
 * ContextConfig class.
 *
 * @see \Drupal\rules\Context\ContextConfig
 */
trait ContextHandlerTrait {

  /**
   * The data processor plugin manager used to process context variables.
   *
   * @var \Drupal\rules\Context\DataProcessorManager
   */
  protected $processorManager;

  /**
   * Prepares plugin context based upon the set context configuration.
   *
   * If no execution state is given, the configuration is applied as far as
   * possible. That means, the configured context values are set and context is
   * refined.
   * If an execution state is available, the plugin is prepared for execution
   * by mapping the variables from the execution state into the plugin context
   * and applying data processors.
   * In addition, it is ensured that all required context is basically
   * available as defined. This include the following checks:
   *  - Required context must have a value set.
   *  - Context may not have NULL values unless the plugin allows it.
   *
   * @param \Drupal\Core\Plugin\ContextAwarePluginInterface $plugin
   *   The plugin that is populated with context values.
   * @param \Drupal\rules\Engine\ExecutionStateInterface $state
   *   The Rules state containing available variables.
   *
   * @throws \Drupal\rules\Exception\RulesEvaluationException
   *   Thrown if an execution state is given, but some context is not satisfied;
   *   e.g. a required context is missing.
   */
  protected function prepareContext(CoreContextAwarePluginInterface $plugin, ExecutionStateInterface $state = NULL) {
    if (isset($this->configuration['context_values'])) {
      foreach ($this->configuration['context_values'] as $name => $value) {
        $plugin->setContextValue($name, $value);
      }
    }
    if ($plugin instanceof ContextAwarePluginInterface) {
      // Getting context values may lead to undocumented exceptions if context
      // is not set right now. So catch those exceptions.
      // @todo: Remove ones https://www.drupal.org/node/2677162 got fixed.
      try {
        $plugin->refineContextDefinitions();
      }
      catch (ContextException $e) {
      }
    }

    // If no execution state has been provided, we are done now.
    if (!$state) {
      return;
    }

    // Map context by apply data selectors.
    if (isset($this->configuration['context_mapping'])) {
      foreach ($this->configuration['context_mapping'] as $name => $selector) {
        $typed_data = $state->fetchDataByPropertyPath($selector);
        $plugin->setContextValue($name, $typed_data);
      }
    }
    // Apply data processors.
    $this->processData($plugin, $state);

    // Finally, ensure all contexts are set as expected now.
    foreach ($plugin->getContextDefinitions() as $name => $definition) {
      if ($plugin->getContextValue($name) === NULL && $definition->isRequired()) {
        // If a context mapping has been specified, the value might end up NULL
        // but valid (e.g. a reference on an empty property). In that case
        // isAllowedNull determines whether the context is conform.
        if (!isset($this->configuration['context_mapping'][$name])) {
          throw new RulesEvaluationException("Required context $name is missing for plugin "
            . $plugin->getPluginId() . '.');
        }
        elseif (!$definition->isAllowedNull()) {
          throw new RulesEvaluationException("The context for $name is NULL, but the context $name in "
            . $plugin->getPluginId() . ' requires a value.');
        }
      }
    }
  }

  /**
   * Gets the definition of the data that is mapped to the given context.
   *
   * @param string $context_name
   *   The name of the context.
   * @param \Drupal\rules\Engine\ExecutionMetadataStateInterface $metadata_state
   *   The metadata state containing metadata about available variables.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface|null
   *   A data definition if the property path could be applied, or NULL if the
   *   context is not mapped.
   *
   * @throws \Drupal\rules\Exception\RulesIntegrityException
   *   Thrown if the data selector that is configured for the context is
   *   invalid.
   */
  protected function getMappedDefinition($context_name, ExecutionMetadataStateInterface $metadata_state) {
    if (isset($this->configuration['context_mapping'][$context_name])) {
      return $metadata_state->fetchDefinitionByPropertyPath($this->configuration['context_mapping'][$context_name]);
    }
  }

  /**
   * Adds provided context values from the plugin to the execution state.
   *
   * @param CoreContextAwarePluginInterface $plugin
   *   The context aware plugin of which to add provided context.
   * @param \Drupal\rules\Engine\ExecutionStateInterface $state
   *   The Rules state where the context variables are added.
   */
  protected function addProvidedContext(CoreContextAwarePluginInterface $plugin, ExecutionStateInterface $state) {
    // If the plugin does not support providing context, there is nothing to do.
    if (!$plugin instanceof ContextProviderInterface) {
      return;
    }
    $provides = $plugin->getProvidedContextDefinitions();
    foreach ($provides as $name => $provided_definition) {
      // Avoid name collisions in the rules state: provided variables can be
      // renamed.
      if (isset($this->configuration['provides_mapping'][$name])) {
        $state->setVariableData($this->configuration['provides_mapping'][$name], $plugin->getProvidedContext($name)->getContextData());
      }
      else {
        $state->setVariableData($name, $plugin->getProvidedContext($name)->getContextData());
      }
    }
  }

  /**
   * Adds the definitions of provided context to the execution metadata state.
   *
   * @param CoreContextAwarePluginInterface $plugin
   *   The context aware plugin of which to add provided context.
   * @param \Drupal\rules\Engine\ExecutionMetadataStateInterface $metadata_state
   *   The execution metadata state to add variables to.
   */
  protected function addProvidedContextDefinitions(CoreContextAwarePluginInterface $plugin, ExecutionMetadataStateInterface $metadata_state) {
    // If the plugin does not support providing context, there is nothing to do.
    if (!$plugin instanceof ContextProviderInterface) {
      return;
    }

    foreach ($plugin->getProvidedContextDefinitions() as $name => $context_definition) {
      if (isset($this->configuration['provides_mapping'][$name])) {
        // Populate the state with the new variable that is provided by this
        // plugin. That is necessary so that the integrity check in subsequent
        // actions knows about the variable and does not throw violations.
        $metadata_state->setDataDefinition(
          $this->configuration['provides_mapping'][$name],
          $context_definition->getDataDefinition()
        );
      }
      else {
        $metadata_state->setDataDefinition($name, $context_definition->getDataDefinition());
      }
    }
  }

  /**
   * Process data context on the plugin, usually before it gets executed.
   *
   * @param \Drupal\Core\Plugin\ContextAwarePluginInterface $plugin
   *   The plugin to process the context data on.
   * @param \Drupal\rules\Engine\ExecutionStateInterface $rules_state
   *   The current Rules execution state with context variables.
   */
  protected function processData(CoreContextAwarePluginInterface $plugin, ExecutionStateInterface $rules_state) {
    if (isset($this->configuration['context_processors'])) {
      foreach ($this->configuration['context_processors'] as $context_name => $processors) {
        $value = $plugin->getContextValue($context_name);
        foreach ($processors as $processor_plugin_id => $configuration) {
          $data_processor = $this->processorManager->createInstance($processor_plugin_id, $configuration);
          $value = $data_processor->process($value, $rules_state);
        }
        $plugin->setContextValue($context_name, $value);
      }
    }
  }

}
