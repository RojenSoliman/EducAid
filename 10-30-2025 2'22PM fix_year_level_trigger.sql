-- Fix the initialize_year_level_history trigger function
-- The column name is 'name' not 'year_level_name'

CREATE OR REPLACE FUNCTION public.initialize_year_level_history()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
DECLARE
    year_level_name TEXT;
BEGIN
    -- If year_level_history is empty and we have a year_level_id, initialize it
    IF (NEW.year_level_history = '[]'::jsonb OR NEW.year_level_history IS NULL)
       AND NEW.year_level_id IS NOT NULL
       AND NEW.current_academic_year IS NOT NULL THEN

        -- Get the year level name from year_levels table (using 'name' column, not 'year_level_name')
        SELECT yl.name INTO year_level_name
        FROM year_levels yl
        WHERE yl.year_level_id = NEW.year_level_id;

        -- Initialize history with current year level
        NEW.year_level_history = jsonb_build_array(
            jsonb_build_object(
                'academic_year', NEW.current_academic_year,
                'year_level_id', NEW.year_level_id,
                'year_level_name', COALESCE(year_level_name, 'Unknown'),
                'updated_at', NOW()
            )
        );
    END IF;

    RETURN NEW;
END;
$function$;
