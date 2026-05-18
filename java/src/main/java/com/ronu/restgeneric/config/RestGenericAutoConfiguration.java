package com.ronu.restgeneric.config;

import com.ronu.restgeneric.dsl.DslParser;
import com.ronu.restgeneric.exception.GlobalExceptionHandler;
import com.ronu.restgeneric.query.FilterCompiler;
import com.ronu.restgeneric.query.OrderByCompiler;
import com.ronu.restgeneric.query.QueryPlanCompiler;
import com.ronu.restgeneric.query.TwoPhaseDetector;
import com.ronu.restgeneric.validation.AllowlistRegistry;
import com.ronu.restgeneric.validation.PathValidator;
import jakarta.persistence.EntityManagerFactory;
import org.springframework.boot.autoconfigure.AutoConfiguration;
import org.springframework.boot.autoconfigure.condition.ConditionalOnMissingBean;
import org.springframework.boot.context.properties.EnableConfigurationProperties;
import org.springframework.context.annotation.Bean;

/**
 * Spring Boot auto-configuration for rest-generic-class Java library.
 * All library beans are registered here via {@code @ConditionalOnMissingBean}
 * so consumers can override any individual component.
 */
@AutoConfiguration
@EnableConfigurationProperties(RestGenericProperties.class)
public class RestGenericAutoConfiguration {

    @Bean
    @ConditionalOnMissingBean
    public AllowlistRegistry allowlistRegistry() {
        return new AllowlistRegistry();
    }

    @Bean
    @ConditionalOnMissingBean
    public PathValidator pathValidator(AllowlistRegistry registry,
                                       EntityManagerFactory emf,
                                       RestGenericProperties props) {
        return new PathValidator(registry, emf, props.getFiltering().getMaxDepth());
    }

    @Bean
    @ConditionalOnMissingBean
    public DslParser dslParser(RestGenericProperties props) {
        return new DslParser(
                props.getFiltering().getMaxDepth(),
                props.getFiltering().getMaxConditions());
    }

    @Bean
    @ConditionalOnMissingBean
    public FilterCompiler filterCompiler() {
        return new FilterCompiler();
    }

    @Bean
    @ConditionalOnMissingBean
    public OrderByCompiler orderByCompiler(AllowlistRegistry registry, PathValidator pathValidator) {
        return new OrderByCompiler(registry, pathValidator);
    }

    @Bean
    @ConditionalOnMissingBean
    public TwoPhaseDetector twoPhaseDetector(PathValidator pathValidator) {
        return new TwoPhaseDetector(pathValidator);
    }

    @Bean
    @ConditionalOnMissingBean
    public QueryPlanCompiler queryPlanCompiler(DslParser parser,
                                               AllowlistRegistry registry,
                                               PathValidator pathValidator,
                                               TwoPhaseDetector twoPhaseDetector) {
        return new QueryPlanCompiler(parser, registry, pathValidator, twoPhaseDetector);
    }

    @Bean
    @ConditionalOnMissingBean
    public GlobalExceptionHandler globalExceptionHandler() {
        return new GlobalExceptionHandler();
    }
}
